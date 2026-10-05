# R2 by Grisma

Enterprise Cloudflare R2 media sync with upload-time format & compression controls, server-side WebP conversion, custom CDN delivery, and native WordPress Core auto-updates.

---

## ⚡ Highlights

- **Pure S3 SigV4 Engine**: 100% pure PHP implementation with zero AWS SDK or third-party vendor dependencies (under 30KB total footprint).
- **Upload-Time Control**: Preset defaults in Settings with full freedom to override format (WebP vs Preserve Original) and compression per upload.
- **Streamlined Server-Side Processing**: Native WebP conversion and JPEG/PNG optimization using GD/Imagick editors with zero browser freezing.
- **Storage Policies**:
  - `Both`: Keep local server copies alongside Cloudflare R2 backup.
  - `R2 Cloud Only`: Automatically purges local server files upon successful R2 upload to preserve server disk space.
- **100% Native WordPress Core Updates**: Hooks directly into WordPress's native `Plugin_Upgrader` sandbox. Automatically detects new GitHub Releases, provides in-admin "Check for Updates", and allows 1-click upgrades and rollbacks without triggering shared hosting security or malware alerts.
- **Universal Compatibility**: Works seamlessly on both headless WordPress setups (Astro, Next.js) and standard classic WordPress sites.

---

## 🚀 Installation

### Option 1: Native GitHub Release Zip (Recommended)
1. Download `r2-by-grisma.zip` from the [Latest Release](https://github.com/iamgrisma/R2-by-Grisma/releases/latest).
2. In your WordPress admin dashboard, navigate to **Plugins > Add New Plugin > Upload Plugin**.
3. Select `r2-by-grisma.zip` and click **Install Now**.
4. Activate the plugin and navigate to **Settings > R2 by Grisma** to enter your Cloudflare R2 credentials.

---

## 🔄 Automated Updates & Rollback

Once installed, **R2 by Grisma** automatically tracks updates directly from GitHub Releases:
- **Automatic Notifications**: Whenever a new version is released on GitHub, WordPress will display an update notification on the Plugins screen.
- **Admin Dashboard**: Under **Settings > R2 by Grisma > Updates & Rollback**, you can:
  - Check for updates immediately with a single click.
  - View changelogs and release dates.
  - Rollback to any previous release safely using WordPress core's native upgrader.

---

## 🛠️ Configuration

Navigate to **Settings > R2 by Grisma**:
- **Cloudflare Account ID**: Found on your Cloudflare dashboard sidebar.
- **R2 Access Key ID & Secret Access Key**: Generated from Cloudflare R2 > Manage R2 API Tokens.
- **R2 Bucket Name**: The name of your R2 bucket.
- **Custom CDN Domain**: Your public custom domain (e.g. `https://objects.yourdomain.com`).
- **Optimization & Formats**: Configure presets for Format Conversion (WebP vs Original), Compression (Yes/No), Quality, Max Dimensions, and Upload Workflow (Prompt vs Automatic).

---

## 📄 License

GPLv2 or later. Created with ❤️ by Grisma.
