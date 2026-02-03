# GitHub Actions Deployment Setup

## Overview
This workflow automatically deploys to your server when you push to the `main` branch.

## Required GitHub Secrets

Go to: **Repository Settings → Secrets and variables → Actions → New repository secret**

| Secret Name | Description | Example |
|-------------|-------------|---------|
| `SSH_HOST` | Server IP address or hostname | `ec2-xx-xxx-xxx-xxx.compute.amazonaws.com` or `192.168.1.100` |
| `SSH_USERNAME` | SSH username | `ec2-user` |
| `SSH_PRIVATE_KEY` | Your private SSH key | Paste contents of `~/.ssh/id_rsa` |
| `SSH_PORT` | SSH port (optional, defaults to 22) | `22` |
| `DEPLOY_PATH` | Deployment path (optional) | `/home/ec2-user/rchat` |

## How to Generate SSH Key Pair

On your local machine:

```bash
# Generate new key pair
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/github_actions_deploy

# Copy public key to server
ssh-copy-id -i ~/.ssh/github_actions_deploy.pub ec2-user@YOUR_SERVER_IP

# Copy private key content for GitHub Secret
cat ~/.ssh/github_actions_deploy
```

Paste the output of the private key (including `-----BEGIN` and `-----END` lines) into the `SSH_PRIVATE_KEY` secret.

## Workflow Process

1. **Trigger**: Push to `main` branch
2. **Setup**: PHP 8.2 + Node.js 20
3. **Install**: Composer + npm dependencies
4. **Test**: Run PHPUnit tests
5. **Build**: Compile frontend assets
6. **Deploy**: Sync files via rsync
7. **Post-deploy**: Migrations, cache clear, permissions

## Server Requirements

- PHP 8.2+
- Composer
- Node.js 20+
- MySQL/PostgreSQL database
- Proper file permissions on storage directory

## Troubleshooting

### Tests failing in CI but passing locally
The workflow uses SQLite for testing. Ensure your tests work with SQLite:

```bash
# Test locally with SQLite
DB_CONNECTION=sqlite php artisan test
```

### Permission issues
Add your web server user to the ec2-user group:

```bash
sudo usermod -aG ec2-user nginx
# or for Apache
sudo usermod -aG ec2-user apache
```

### Skipping tests temporarily
Comment out the "Run PHPUnit tests" step in `.github/workflows/deploy.yml`.
