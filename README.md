# Video 404 Cleaner

A powerful WordPress plugin that automatically detects and cleans up broken video files in your media library. When a video file returns a 404 error (or other HTTP error codes), the plugin removes all references to it from your posts and moves the file to the trash.

## Features

### 🔍 **Smart Video Detection**
- Scans all video files in your WordPress media library
- Supports multiple video formats: MP4, MOV, AVI, WMV, WebM, OGG, MKV, 3GP, FLV
- Detects various HTTP error codes: 404, 403, 500, 502, 503, 504

### 🧹 **Comprehensive Cleanup**
- Removes video references from post content
- Handles Gutenberg blocks, shortcodes, and HTML video tags
- Unlinks videos from parent posts
- Moves broken videos to trash automatically

### ⚡ **Performance Optimized**
- Batch processing for large media libraries
- Configurable batch sizes (10-200 videos per batch)
- AJAX-powered scanning with progress indicators
- Memory-efficient processing

### 🛠️ **Advanced Configuration**
- Customizable HTTP timeout settings
- Configurable error codes to detect
- Automatic scanning schedules (daily, weekly, monthly)
- Comprehensive logging system

### 📊 **Detailed Reporting**
- Real-time scan progress with visual indicators
- Detailed reports of broken videos found
- Action logs showing what was cleaned
- Error tracking and logging

## Installation

1. Upload the plugin files to `/wp-content/plugins/video-404-cleaner/` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to **Tools > Video 404 Cleaner** in your WordPress admin

## Usage

### Manual Scanning

1. Go to **Tools > Video 404 Cleaner**
2. Click **"Run scan now"** to start a manual scan
3. Monitor the progress bar for large libraries
4. Review the results in the "Last Report" section

### Settings Configuration

Navigate to the **Settings** tab to configure:

- **Batch Size**: Number of videos processed per batch (10-200)
- **HTTP Timeout**: Request timeout in seconds (5-60)
- **Auto Scan**: Enable/disable automatic scanning
- **Scan Frequency**: Daily, weekly, or monthly scans
- **Error Codes**: Select which HTTP codes to treat as "broken"
- **Logging**: Enable/disable detailed logging

### Logs

View detailed operation logs in the **Logs** tab:
- Real-time scan progress
- Error messages and debugging info
- Action history
- Clear logs functionality

## WP-CLI Support

Use the command line for advanced operations:

```bash
# Run a scan via WP-CLI
wp video-404 check
```

## Technical Details

### Supported Video Formats
- MP4 (`video/mp4`)
- QuickTime (`video/quicktime`)
- Windows Media (`video/x-ms-wmv`)
- Flash Video (`video/x-flv`)
- WebM (`video/webm`)
- OGG (`video/ogg`, `application/ogg`)
- Matroska (`video/x-matroska`)
- AVI (`video/avi`)
- MOV (`video/mov`)
- WMV (`video/wmv`)
- 3GP (`video/3gp`)
- MKV (`video/mkv`)

### Content Cleaning Patterns
The plugin removes video references using these patterns:
- Gutenberg video blocks (by attachment ID and URL)
- WordPress video shortcodes
- HTML `<video>` tags
- HTML `<video>` with `<source>` tags
- Direct links to video files

### Error Detection
Configurable HTTP error codes:
- **404**: Not Found
- **403**: Forbidden
- **500**: Internal Server Error
- **502**: Bad Gateway
- **503**: Service Unavailable
- **504**: Gateway Timeout

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Sufficient server memory for batch processing
- Write permissions to `/wp-content/uploads/` for logging

## Security Features

- Nonce verification for all forms
- Capability checks (`manage_options`)
- Input sanitization and validation
- Secure file handling
- Error logging without exposing sensitive data

## Performance Considerations

### Large Media Libraries
For sites with thousands of videos:
- Use smaller batch sizes (10-50)
- Increase HTTP timeout for slow servers
- Enable logging for monitoring
- Run scans during low-traffic periods

### Server Resources
- Each video requires an HTTP request
- Batch processing prevents memory issues
- Configurable timeouts prevent hanging requests
- Progress indicators show real-time status

## Troubleshooting

### Common Issues

**Scan stops or times out:**
- Reduce batch size in settings
- Increase HTTP timeout
- Check server memory limits

**Videos not detected:**
- Verify video format is supported
- Check file permissions
- Review error logs

**Content not cleaned:**
- Ensure videos are properly linked to posts
- Check for custom video implementations
- Review content patterns in logs

### Debug Mode
Enable logging in settings to troubleshoot:
1. Go to **Settings** tab
2. Enable "Logging"
3. Run a scan
4. Check **Logs** tab for detailed information

## Changelog

### Version 1.1.0
- Added comprehensive settings panel
- Implemented batch processing for large libraries
- Enhanced error handling and logging
- Added support for more video formats
- Improved admin interface with tabs
- Added WP-CLI support
- Enhanced security features

### Version 1.0.0
- Initial release
- Basic video scanning functionality
- Manual cleanup of broken videos
- Simple admin interface

## Support

For support, feature requests, or bug reports, please contact [DWAY SRL](https://dway.agency).

## License

This plugin is licensed under the GPL v2 or later.

---

**Developed by [DWAY SRL](https://dway.agency)** - Professional WordPress Development
