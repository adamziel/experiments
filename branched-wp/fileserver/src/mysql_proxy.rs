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

/// Rewrite bare `wp_` identifier prefixes to `b{id}_wp_`, leaving string
/// literals and comments untouched.
///
/// Rules:
///   - Inside `'…'` or `"…"` strings the text is left alone. SQL's `''` and
///     MySQL's `\\'` both escape an embedded quote; backticked identifiers
///     (`\`wp_x\``) rewrite as normal.
///   - Line comments `-- …\n` and block comments `/* … */` are skipped.
///   - An occurrence of `wp_` is rewritten only when the character immediately
///     before it is not part of an identifier (so `my_wp_xyz` or `swp_` are
///     left alone).
pub fn rewrite_wp_prefix(sql: &str, prefix: &str) -> String {
    let bytes = sql.as_bytes();
    let mut out = String::with_capacity(sql.len() + prefix.len());
    let mut i = 0;
    let mut prev_byte: Option<u8> = None;

    while i < bytes.len() {
        let c = bytes[i];

        // -- line comment
        if c == b'-' && i + 1 < bytes.len() && bytes[i + 1] == b'-' {
            while i < bytes.len() && bytes[i] != b'\n' {
                out.push(bytes[i] as char);
                i += 1;
            }
            prev_byte = Some(b'\n');
            continue;
        }

        // /* block comment */
        if c == b'/' && i + 1 < bytes.len() && bytes[i + 1] == b'*' {
            out.push_str("/*");
            i += 2;
            while i < bytes.len() {
                if bytes[i] == b'*' && i + 1 < bytes.len() && bytes[i + 1] == b'/' {
                    out.push_str("*/");
                    i += 2;
                    break;
                }
                out.push(bytes[i] as char);
                i += 1;
            }
            prev_byte = Some(b' ');
            continue;
        }

        // String literal: ' or "
        if c == b'\'' || c == b'"' {
            let quote = c;
            out.push(quote as char);
            i += 1;
            while i < bytes.len() {
                let b = bytes[i];
                // MySQL-style backslash escape: copy next byte verbatim
                if b == b'\\' && i + 1 < bytes.len() {
                    out.push(b as char);
                    out.push(bytes[i + 1] as char);
                    i += 2;
                    continue;
                }
                // SQL-standard doubled-quote escape: 'it''s'
                if b == quote && i + 1 < bytes.len() && bytes[i + 1] == quote {
                    out.push(quote as char);
                    out.push(quote as char);
                    i += 2;
                    continue;
                }
                out.push(b as char);
                i += 1;
                if b == quote {
                    break;
                }
            }
            prev_byte = Some(quote);
            continue;
        }

        // Match bare `wp_` in identifier position.
        if (c == b'w' || c == b'W')
            && i + 2 < bytes.len()
            && (bytes[i + 1] == b'p' || bytes[i + 1] == b'P')
            && bytes[i + 2] == b'_'
            && !is_ident_cont(prev_byte)
        {
            out.push_str(prefix);
            i += 3;
            prev_byte = Some(b'_');
            continue;
        }

        out.push(c as char);
        prev_byte = Some(c);
        i += 1;
    }

    out
}

/// Identifier-continuation check: letters, digits, underscore, or `$`
/// (MySQL permits `$` in identifiers). A `None` preceding byte (start of
/// input) counts as non-identifier so leading `wp_` is rewritten.
fn is_ident_cont(b: Option<u8>) -> bool {
    match b {
        Some(b) => b.is_ascii_alphanumeric() || b == b'_' || b == b'$',
        None => false,
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
        let rewritten = rewrite_wp_prefix(sql, &prefix);
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rewrites_table_identifier() {
        assert_eq!(
            rewrite_wp_prefix("SELECT * FROM wp_options", "b2_wp_"),
            "SELECT * FROM b2_wp_options"
        );
    }

    #[test]
    fn preserves_single_quoted_string() {
        assert_eq!(
            rewrite_wp_prefix(
                "UPDATE wp_options SET option_value='wp_capabilities' \
                 WHERE option_name='x'",
                "b1_wp_"
            ),
            "UPDATE b1_wp_options SET option_value='wp_capabilities' \
             WHERE option_name='x'"
        );
    }

    #[test]
    fn preserves_double_quoted_string() {
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_usermeta(meta_key) VALUES(\"wp_capabilities\")",
                "b1_wp_"
            ),
            "INSERT INTO b1_wp_usermeta(meta_key) VALUES(\"wp_capabilities\")"
        );
    }

    #[test]
    fn preserves_string_with_sql_doubled_quote() {
        // 'it''s wp_capabilities' — the embedded `wp_` must survive.
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_x(v) VALUES('it''s wp_capabilities')",
                "b3_wp_"
            ),
            "INSERT INTO b3_wp_x(v) VALUES('it''s wp_capabilities')"
        );
    }

    #[test]
    fn preserves_string_with_backslash_escape() {
        assert_eq!(
            rewrite_wp_prefix(
                "INSERT INTO wp_x(v) VALUES('a\\'b wp_capabilities')",
                "b3_wp_"
            ),
            "INSERT INTO b3_wp_x(v) VALUES('a\\'b wp_capabilities')"
        );
    }

    #[test]
    fn skips_line_comment() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_options -- reference wp_capabilities\nWHERE x=1",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_options -- reference wp_capabilities\nWHERE x=1"
        );
    }

    #[test]
    fn skips_block_comment() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_options /* about wp_capabilities */ WHERE x=1",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_options /* about wp_capabilities */ WHERE x=1"
        );
    }

    #[test]
    fn does_not_rewrite_inside_identifier() {
        // `my_wp_x` must not become `my_b1_wp_x`.
        assert_eq!(
            rewrite_wp_prefix("SELECT my_wp_x FROM t", "b1_wp_"),
            "SELECT my_wp_x FROM t"
        );
    }

    #[test]
    fn rewrites_multiple_occurrences() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT wp_options.id FROM wp_options JOIN wp_posts",
                "b1_wp_"
            ),
            "SELECT b1_wp_options.id FROM b1_wp_options JOIN b1_wp_posts"
        );
    }

    #[test]
    fn where_clause_string_not_rewritten() {
        assert_eq!(
            rewrite_wp_prefix(
                "SELECT * FROM wp_usermeta WHERE meta_key='wp_capabilities'",
                "b1_wp_"
            ),
            "SELECT * FROM b1_wp_usermeta WHERE meta_key='wp_capabilities'"
        );
    }

    #[test]
    fn mixed_case_prefix_is_rewritten() {
        assert_eq!(
            rewrite_wp_prefix("SELECT * FROM WP_OPTIONS", "b1_wp_"),
            "SELECT * FROM b1_wp_OPTIONS"
        );
    }

    #[test]
    fn unterminated_string_is_passed_through() {
        // Don't panic on a malformed query; pass bytes through unchanged
        // from the opening quote to end of input.
        let out = rewrite_wp_prefix("SELECT 'oops wp_capabilities", "b1_wp_");
        assert!(out.contains("'oops wp_capabilities"));
    }
}
