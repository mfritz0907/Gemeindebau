# Gemeindebau

## SFTP deployment workflow

The project deploys to the remote host using the GitHub Actions workflow defined in `.github/workflows/main.yml`. On every push to `main` it:

1. Checks out the repository contents.
2. Runs [`sand4rt/ftp-deployer@v1.5`](https://github.com/Sand4rt/ftp-deployer) with SFTP enabled, uploading from the repository root (`./`) to `/kunden/homepages/19/d512186843/htdocs/arthurssite/fromGit` while excluding only the `.git` directory.

### Why a run can show "completed" but nothing appears remotely

A green job in the log only means the action finished without hitting an error condition. Nothing gets copied if the action has nothing to send or cannot write to the target directory. Common causes are:

- **Empty or mismatched source directory:** If `local_folder` is empty (for example, because the repository was checked out to a different path or contains only ignored files), the deployer has nothing to upload.
- **Remote path/permissions:** The action does not create missing directories. If `/kunden/homepages/19/d512186843/htdocs/arthurssite/fromGit` does not exist or the SFTP user lacks write access, the transfer silently skips files.
- **Secrets or connection limits:** Incorrect `HOST`, `USER`, or `PASSWORD` values, or an SFTP server refusing connections, can prevent transfers even though the step exits successfully.
- **Exclude patterns:** Only files matching the `include` list and not matching `exclude` are sent. With `include: ["**/*"]`, everything except `.git/**` should upload, but additional excludes would filter files.

## Local setup

1. Copy `dbconnect/config_local.php.example` to `dbconnect/config_local.php` and fill in your database credentials.
2. Optionally set `GOOGLE_MAPS_API_KEY` in the same file so the Street View pages can load the Maps JavaScript API.
3. Run the PHP scripts via your preferred web server or `php -S localhost:8000` from the repository root.

### Quick checks

- The workflow prints the repository contents (via `find . -maxdepth 3 -type f`) before deploying so you can confirm files are present in the runner workspace.
- It also counts the number of files available for upload (excluding `.git`). The **Summarize deployment result** step will tell you whether the upload step succeeded and whether there were any files to send. You can see both messages directly in the GitHub Actions log for the `SFTP Deploy` workflow run.

If files are present locally, confirm the remote directory exists and is writable for the configured user.
