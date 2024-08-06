# Add-Settings-Links

A wordpress plugin which adds direct links to the plugins admin page for plugins that do not have direct links to their settings panel listed on the plugins page

**Partially Working Functionality**

Plugins that create top level menu items on the sidebar, and submenu items under "settings" have the correct settings page urls added to plugins.php.

--------------------------------

**Known bugs** 

For plugins that create submenu items under tools or users or unconventional locations (lets say appearances for example) it does not correctly identify the url.

--------------------------------

**Planned Features**

Add a button to edit the link, allowing users to set a custom slug for each plugin's settings page. This button should appear when the mouse hovers over the settings link. This feature enables the ability to manually set the URL in case the plugin fails to identify the correct slug.
