# Cloudflare R2 image storage

User uploaded product photos, galleries, and logos are sent to the authenticated `POST /api/v1/images` endpoint. Laravel validates JPEG, PNG, or WebP files up to 5 MB, then stores them under `images/` on the `s3` disk. The response URL is saved with the product or system settings.

Configure the backend environment with an R2 S3 API token that has read and write access to the bucket:

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<R2 access key ID>
AWS_SECRET_ACCESS_KEY=<R2 secret access key>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=<bucket name>
AWS_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
AWS_URL=https://<public bucket domain>
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Set `AWS_URL` to the bucket's public `r2.dev` URL or, preferably, a custom domain attached to the bucket. The bucket must be publicly readable through that domain for the returned image URLs to render in the app. Restart Laravel after changing environment values. Do not commit the populated `.env` file or R2 credentials.
