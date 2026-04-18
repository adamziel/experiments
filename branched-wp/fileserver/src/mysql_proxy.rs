use anyhow::Result;
use async_trait::async_trait;
use opensrv_mysql::*;
use std::io;
use std::sync::Arc;
use tokio::io::AsyncWrite;

use crate::store::Store;

pub struct MysqlHandler {
    store: Arc<Store>,
    branch_id: i64,
}

impl MysqlHandler {
    pub fn new(store: Arc<Store>) -> Self {
        Self { store, branch_id: 1 }
    }
}

#[async_trait]
impl<W: AsyncWrite + Unpin + Send> AsyncMysqlShim<W> for MysqlHandler {
    type Error = io::Error;

    async fn on_prepare<'a>(
        &'a mut self,
        _: &'a str,
        info: StatementMetaWriter<'a, W>,
    ) -> io::Result<()> {
        info.reply(0, &[] as &[Column], &[] as &[Column]).await
    }

    async fn on_execute<'a>(
        &'a mut self,
        _: u32,
        _: ParamParser<'a>,
        results: QueryResultWriter<'a, W>,
    ) -> io::Result<()> {
        results.completed(OkResponse::default()).await
    }

    async fn on_close<'a>(&'a mut self, _: u32)
    where
        W: 'async_trait,
    {
    }

    async fn on_init<'a>(
        &'a mut self,
        schema: &'a str,
        w: InitWriter<'a, W>,
    ) -> io::Result<()> {
        match self.store.branch_id(schema) {
            Ok(id) => {
                self.branch_id = id;
                w.ok().await
            }
            Err(_) => w.error(ErrorKind::ER_BAD_DB_ERROR, schema.as_bytes()).await,
        }
    }

    async fn on_query<'a>(
        &'a mut self,
        sql: &'a str,
        results: QueryResultWriter<'a, W>,
    ) -> io::Result<()> {
        let prefix = format!("b{}_wp_", self.branch_id);
        let rewritten = sql.replace("wp_", &prefix);
        match self.store.query_rows(&rewritten) {
            Err(e) => {
                let msg = e.to_string();
                results.error(ErrorKind::ER_UNKNOWN_ERROR, msg.as_bytes()).await
            }
            Ok((col_names, rows)) => {
                if col_names.is_empty() {
                    return results.completed(OkResponse::default()).await;
                }
                let cols: Vec<Column> = col_names
                    .iter()
                    .map(|name| Column {
                        table: String::new(),
                        column: name.clone(),
                        coltype: ColumnType::MYSQL_TYPE_STRING,
                        colflags: ColumnFlags::empty(),
                    })
                    .collect();
                let mut rw = results.start(&cols).await?;
                for row in &rows {
                    for val in row {
                        match val {
                            rusqlite::types::Value::Null => rw.write_col(None::<String>)?,
                            rusqlite::types::Value::Integer(i) => rw.write_col(*i)?,
                            rusqlite::types::Value::Real(f) => rw.write_col(*f)?,
                            rusqlite::types::Value::Text(s) => rw.write_col(s.as_str())?,
                            rusqlite::types::Value::Blob(b) => rw.write_col(b.as_slice())?,
                        }
                    }
                    rw.end_row().await?;
                }
                rw.finish().await
            }
        }
    }
}

pub async fn run_mysql_proxy(addr: &str, store: Arc<Store>) -> Result<()> {
    let listener = tokio::net::TcpListener::bind(addr).await?;
    log::info!("MySQL proxy listening on {}", addr);
    loop {
        let (stream, peer) = listener.accept().await?;
        log::info!("mysql: connection from {}", peer);
        let store = Arc::clone(&store);
        tokio::spawn(async move {
            let handler = MysqlHandler::new(store);
            let (reader, writer) = tokio::io::split(stream);
            if let Err(e) = AsyncMysqlIntermediary::run_on(handler, reader, writer).await {
                log::error!("mysql: session error: {}", e);
            }
        });
    }
}
