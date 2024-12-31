# Contributing

Local development uses Docker and wp-env. To get started, run the following commands:

```bash
composer install
npm install
npx wp-env start
```

and visit http://localhost:8888 in your browser. Log in at http://localhost:8888/wp-admin with the username `admin` and password `password`.

# Distribution

Requires [wp-cli](https://make.wordpress.org/cli/handbook/guides/installing/) and wp-cli [dist-archive-command](https://github.com/wp-cli/dist-archive-command/).

```bash
# After installing wp-cli
wp package install wp-cli/dist-archive-command:@stable
```

The `.distignore` file is used to exclude files from the distribution archive.

The Composer `create-plugin-archive` script will generate the i18n `.pot` file and create the `.zip` file in the dist-archive directory.

```bash
composer create-plugin-archive
# Which really just runs:
# wp i18n make-pot . languages/add-settings-links.pot --domain=add-settings-links --include=add-settings-links.php
# wp dist-archive . ./dist-archive --plugin-dirname=add-settings-links --create-target-dir
```

