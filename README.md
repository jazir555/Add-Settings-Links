# Add-Settings-Links

A wordpress plugin which adds direct links to the plugins admin page for plugins that do not have direct links to their settings panel listed on the plugins page

**Partially Working Functionality**

Plugins that create top level menu items on the sidebar, and submenu items under "settings" have the correct settings page urls added to plugins.php.

--------------------------------

**Known bugs** 

For plugins that create submenu items under tools or users or unconventional locations (lets say appearances for example) it does not correctly identify the url.

--------------------------------

**Planned Features**

Adding a button to edit the link to set a custom slug for each plugin's settings page that appears when the mouse is hovered over the settings link. That way if the plugin fails to identify the correct url, it can be set manually.
