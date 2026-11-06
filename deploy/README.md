# Deploying Choronai (one server)

Everything runs with Docker on one Linux server (tested size: AWS EC2
**t2.micro**, 1 GB RAM, in Singapore). Four containers:

| Container | What | Open to the internet |
| --- | --- | --- |
| `web` | Caddy: the web server, HTTPS when you have a domain | **yes** (80, 443) |
| `front` | Next.js frontend (`next start`) | no |
| `api` | Laravel on PHP-FPM | no |
| `db` | PostgreSQL 16, data kept in a Docker volume | no |

The browser only talks to the frontend; the frontend talks to Laravel inside
the server. Product photos stay in Cloudflare R2.

All commands below run **on the server**, in the `Choronai-backend` folder,
unless it says "on your computer". To keep them short:

```bash
alias dc='docker compose -f deploy/compose.prod.yaml --env-file deploy/.env.production'
```

## 1. AWS (once)

1. EC2 → Instances → select the server → **Instance state → Start**.
2. Security group of the server, **inbound rules**:
   - SSH (22): **My IP** only
   - HTTP (80) and HTTPS (443): Anywhere (`0.0.0.0/0`)
3. The address stays `52.220.42.228` (Elastic IP).
4. Connect: `ssh -i your-key.pem ubuntu@52.220.42.228`
   (Amazon Linux: user `ec2-user`).

## 2. Prepare the server (once)

```bash
# 2 GB swap: building the frontend needs more than 1 GB of memory.
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab

# Docker (Ubuntu). Amazon Linux: sudo dnf install -y docker git && sudo systemctl enable --now docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER   # then log out and in again

# The two projects side by side (folder names matter).
git clone https://github.com/Ratha-gift/Choronai_back.git Choronai-backend
git clone https://github.com/Ratha-gift/Choronai_front.git Choronai-front
cd Choronai-backend
```

## 3. Settings (once)

```bash
cp deploy/.env.production.example deploy/.env.production
nano deploy/.env.production
```

Fill in:

- `DB_PASSWORD`: a long random password (`openssl rand -hex 24`).
- `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`: the R2 token (same as in your
  development `.env`).
- `APP_URL`, `FRONTEND_URL`: `http://52.220.42.228` (or your domain later).
- `APP_KEY`: after the first build (step 4):
  `dc run --rm api php artisan key:generate --show`, paste the result.

`deploy/.env.production` is in `.gitignore`. Never commit it, never send it in chat.

## 4. Start

```bash
dc up -d --build                         # first time: 5–15 minutes on a t2.micro
dc exec api php artisan migrate --force  # create the tables
```

Open **http://52.220.42.228**.

### Data

Either start with the sample data:

```bash
dc exec api php artisan db:seed --force
```

or move the database from your computer to the server:

```bash
# On your computer (Choronai-backend):
docker compose exec -T db pg_dump -U choronai -Fc choronai > choronai.dump
scp -i your-key.pem choronai.dump ubuntu@52.220.42.228:~/Choronai-backend/

# On the server:
dc exec -T db pg_restore -U choronai -d choronai --clean --if-exists --no-owner < choronai.dump
rm choronai.dump
```

Everyone signs in again afterwards (the old sign-in tokens are from the other
computer).

## 5. Update to a new version

```bash
cd ~/Choronai-front && git pull
cd ~/Choronai-backend && git pull
dc up -d --build
dc exec api php artisan migrate --force
```

## 6. Every day

| What | Command |
| --- | --- |
| Is everything running? | `dc ps` |
| Logs | `dc logs -f --tail 100 api front web` |
| Restart | `dc restart` |
| Back up the database | `dc exec -T db pg_dump -U choronai -Fc choronai > backup-$(date +%F).dump` |
| Memory use | `docker stats --no-stream` |

Make a backup every day, for example with `crontab -e`:

```cron
0 2 * * * cd ~/Choronai-backend && docker compose -f deploy/compose.prod.yaml --env-file deploy/.env.production exec -T db pg_dump -U choronai -Fc choronai > ~/backups/choronai-$(date +\%F).dump
```

## 7. Domain and HTTPS (recommended)

With only the IP address the site runs on plain HTTP: passwords travel
unencrypted, and browsers allow fewer features (no installable app, no offline
start). With a domain:

1. In your DNS, point an `A` record (e.g. `pos.example.com`) to `52.220.42.228`.
2. In `deploy/.env.production`: `SITE_ADDRESS=pos.example.com`, and
   `APP_URL` / `FRONTEND_URL` = `https://pos.example.com`.
3. `dc up -d`. Caddy gets the certificate by itself.

## Problems

| Problem | Fix |
| --- | --- |
| The build stops with "Killed" | No swap: do step 2 again (`free -h` should show 2 GB swap). |
| "Cannot reach the Choronai API" | `dc logs api`: often `APP_KEY` or `DB_PASSWORD` missing. After changing `.env.production`: `dc up -d`. |
| Photos do not upload | R2 keys missing or wrong in `.env.production`. |
| Site does not open at all | Security group: port 80 must be open; `dc ps` shows `web` running. |
