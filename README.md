# Add Settings Links

## Description

The **Add Settings Links** plugin allows administrators to add custom settings links to plugin entries on the Plugins page, providing quick access to configuration options.

## Features

- **Live-Search:** Quickly filter plugins by name.
- **Manual Overrides:** Enter custom URLs for plugin settings pages.
- **Real-Time Validation:** Immediate feedback on URL validity.
- **Accessibility:** Enhanced for users relying on assistive technologies.
- **Performance Optimized:** Efficient event handling and selector caching.

## Installation

1. Upload the `add-settings-links` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to `Settings > Add Settings Links` to configure manual overrides.

## Usage

- **Live-Search:** Use the search box to filter plugins by name.
- **Manual Overrides:** Enter comma-separated URLs in the provided fields to override default settings links.

## Troubleshooting

- **Error Message Not Displaying:**
  - Ensure that each input field has a corresponding `.asl-error-message` span.
  - Check the browser console for any JavaScript errors.

- **Invalid URL Alert Not Appearing:**
  - Verify that the `ASL_Settings.invalid_url_message` is correctly localized in PHP.

## Frequently Asked Questions

- **Can I add multiple URLs for a single plugin?**
  - Yes, enter comma-separated URLs in the manual overrides field.

- **Is there server-side validation?**
  - Yes, inputs are sanitized and validated on the server side to ensure data integrity.

