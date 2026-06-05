# WP OneClick Installer

`WP OneClick Installer` is a DirectAdmin user-level plugin for installing WordPress quickly and signing into `wp-admin` with a short-lived One-click Login token.

## Features

- Install WordPress for the current DirectAdmin user domain.
- Create the database and database user with the DirectAdmin user prefix.
- Use `wp_set_auth_cookie()` through a generated MU plugin.
- Store only hashed one-time login tokens.
- Expire login tokens after 60 seconds and delete them after use.
- Avoid storing the WordPress admin password in plugin metadata.
- Log install and login-token actions per user.

## Directory structure

```text
wp_oneclick_installer/
├── hooks/
│   └── user_txt.html
├── images/
│   └── user_icon.svg
├── lib/
│   ├── bootstrap.php
│   ├── DirectAdminClient.php
│   ├── Logger.php
│   ├── Plugin.php
│   └── TokenManager.php
├── scripts/
│   ├── da_helper.sh
│   ├── install.sh
│   └── uninstall.sh
├── user/
│   ├── index.html
│   └── oneclick.raw
├── install.sh
├── plugin.conf
└── README.md
```

At runtime the plugin also creates:

```text
/usr/local/directadmin/plugins/wp_oneclick_installer/data/users/<da-user>/
├── activity.log
├── bin/wp-cli.phar
├── csrf/
├── sites.json
└── tokens/
```

## Requirements

- DirectAdmin installed at `/usr/local/directadmin`
- PHP CLI available as `/usr/local/bin/php`
- `curl`, `tar`, and `rsync`
- `sudo` available for plugin helper execution
- Outbound HTTPS access to download WP-CLI if `wp` is not already installed
- Root install so the plugin can place `/etc/sudoers.d/wp-oneclick-installer`

## Install

Copy the plugin source to a working directory, then run:

```sh
chmod +x install.sh
./install.sh install
```

This copies the plugin into:

```text
/usr/local/directadmin/plugins/wp_oneclick_installer
```

Then log into DirectAdmin as a user and open:

```text
/CMD_PLUGINS/wp_oneclick_installer
```

## Package

To build a tarball for DirectAdmin deployment:

```sh
./install.sh package
```

This creates:

```text
./wp_oneclick_installer.tar.gz
```

## Uninstall

```sh
./install.sh uninstall
```

This removes the plugin directory from DirectAdmin.

## How it works

### WordPress install flow

1. List current user domains from DirectAdmin user data files, with a sudo helper fallback.
2. Validate the selected domain, install path, email, and admin username.
3. Create a database via a locked-down helper that calls `CMD_API_DATABASES` as root.
4. Use system `wp` if present, otherwise download `wp-cli.phar`.
5. Run:
   - `wp core download`
   - `wp config create`
   - `wp core install`
6. Save site metadata to `sites.json`.
7. Write `wp-content/mu-plugins/da-oneclick-login.php`.

### One-click Login flow

1. The DirectAdmin page posts to `user/oneclick.raw`.
2. The plugin creates a random token with `random_bytes()`.
3. Only the SHA-256 hash is stored in the token JSON file.
4. The token expires in 60 seconds.
5. WordPress MU plugin receives `?da_oneclick_login=TOKEN`.
6. The MU plugin validates the token hash, owner, expiry, and user ID.
7. On success it deletes the token file, calls `wp_set_current_user()` and `wp_set_auth_cookie()`, then redirects to `/wp-admin/`.
8. On failure it returns HTTP 403.

## Security notes

- No plain-text WordPress password is stored in `sites.json`.
- Login token files are stored outside the web root.
- Token files are written with mode `0600` when possible.
- Output is HTML-escaped.
- CSRF protection uses a DirectAdmin-session-bound token file.
- Installation path is restricted to `public_html` and child paths only.
- Cross-user site access is blocked by owner checks in metadata.
- Database creation is delegated only to `scripts/da_helper.sh` through a dedicated sudoers rule.

## Testing checklist

1. Open the plugin in DirectAdmin user level.
2. Install WordPress to a test domain path such as `public_html/demo`.
3. Confirm the site loads and `wp-admin` exists.
4. Click `Login WordPress` and verify immediate sign-in.
5. Reuse the same token URL and confirm it fails.
6. Wait longer than 60 seconds before opening the token URL and confirm it fails.
7. Inspect `data/users/<user>/activity.log` for recorded actions.

## Notes for production hardening

- If your server uses a custom MySQL host, export `DA_WP_DB_HOST` for the plugin runtime.
- If you want to keep user data outside the plugin tree, adapt `Plugin::$dataRoot` and update MU plugin token paths accordingly.
- For mixed HTTP/HTTPS environments, review the generated site URL and adjust it if the domain should not default to `https://`.
