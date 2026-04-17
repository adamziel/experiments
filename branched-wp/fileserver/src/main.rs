mod smb;
mod smb_proto;
mod sftp;
mod store;

use anyhow::Result;
use clap::Parser;
use std::path::PathBuf;
use std::sync::Arc;

#[derive(Parser, Debug)]
#[command(name = "fileserver", about = "SFTP + SMB2 fileserver backed by branched-wp SQLite store")]
struct Cli {
    /// Path to the branched-wp SQLite database
    #[arg(long)]
    db: PathBuf,

    /// SFTP listen address
    #[arg(long, default_value = "0.0.0.0:2222")]
    sftp_addr: String,

    /// SMB2 listen address
    #[arg(long, default_value = "0.0.0.0:445")]
    smb_addr: String,
}

#[tokio::main]
async fn main() -> Result<()> {
    env_logger::init();
    let cli = Cli::parse();

    let store = Arc::new(store::Store::open(&cli.db)?);

    let sftp_store = Arc::clone(&store);
    let sftp_addr = cli.sftp_addr.clone();
    let sftp_handle = tokio::spawn(async move {
        if let Err(e) = sftp::run_sftp_server(&sftp_addr, sftp_store).await {
            log::error!("SFTP server error: {}", e);
        }
    });

    let smb_store = Arc::clone(&store);
    let smb_addr = cli.smb_addr.clone();
    let smb_handle = tokio::spawn(async move {
        if let Err(e) = smb::run_smb_server(&smb_addr, smb_store).await {
            log::error!("SMB2 server error: {}", e);
        }
    });

    tokio::select! {
        _ = sftp_handle => {},
        _ = smb_handle => {},
    }

    Ok(())
}
