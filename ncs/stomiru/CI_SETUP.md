# CI/CD Setup Guide (GitLab & GitHub)

This project uses a CI/CD configuration that supports both **GitLab CI** (`.gitlab-ci.yml`) and **GitHub Actions** (`.github/workflows/deploy.yml`). 
To prevent accidental deployments when sharing or cloning the source code, the CI/CD system is designed with an **Opt-in** mechanism.

**By default, NO deployment jobs will run.** You must configure specific variables depending on your platform to enable them.

---

## 1. Enable Deployment (Per Repository)

Depending on the target environment for the repository, add the corresponding variable.
- **GitLab:** Go to `Settings -> CI/CD -> Variables`.
- **GitHub:** Go to `Settings -> Secrets and variables -> Actions -> Variables`.

| Variable Name | Type | GitLab Configuration | GitHub Configuration | Purpose |
|---|---|---|---|---|
| `ENABLE_DEV_DEPLOY` | Variable | Scope `All`, `Unprotect` | Environment `dev` or Repository Var | Enable deployment for the `dev` branch |
| `ENABLE_STG_DEPLOY` | Variable | Scope `All`, `Unprotect` | Environment `stg` or Repository Var | Enable deployment for the `staging` branch |
| `ENABLE_PROD_DEPLOY` | Variable | Scope `All`, `Unprotect` | Environment `prod` or Repository Var | Enable deployment for the `main` branch |

*Example: If the repository is only used for Development, just add the variable `ENABLE_DEV_DEPLOY = true`.*

---

## 2. Server Connection & Environment Parameters

To deploy successfully, you **MUST** declare the following variables and secrets.

> [!IMPORTANT]
> **For GitLab:** 
> - Go to `Settings -> CI/CD -> Variables`. 
> - Pay attention to the **Type** (`File` or `Variable`) and the **Environment scope** (`dev`, `stg`, `prod`).
>
> **For GitHub:** 
> - Since GitHub separates `Variables` (public) and `Secrets` (encrypted), make sure to add them to the correct section under `Settings -> Secrets and variables -> Actions`.
> - To scope them by environment, first create the Environments under `Settings -> Environments` (name them `dev`, `stg`, `prod`), then add the Secrets/Variables directly inside that specific Environment!

### Configuration Details

| Name | GitLab (Type / Scope / Flags) | GitHub (Location) | Description & Example |
|---|---|---|---|
| **`SERVER_IP`** | `Variable` / `dev, stg, prod` | `Environment Variables` | The server's IP address. Ex: `192.168.1.1` |
| **`SERVER_USER`** | `Variable` / `dev, stg, prod` | `Environment Variables` | SSH username for the server. Ex: `ubuntu` |
| **`PROJECT_DIR`** | `Variable` / `dev, stg, prod` | `Environment Variables` | The directory on the server containing `docker-compose.yml`. |
| **`STATIC_DIR`** | `Variable` / `dev, stg, prod` | `Environment Variables` | The public directory for the Frontend build. |
| **`SERVER_ID_RSA`** | **`File`** / `dev, stg, prod` / `Unmask` | **`Environment Secrets`** | Private SSH key content (`.pem` or `id_rsa`). |
| **`SUDO_PASSWORD`** | `Variable` / `dev, stg, prod` / **`Mask`** | **`Environment Secrets`** | Password to run `sudo` commands (used when deploying frontend). |

| **`ENV_DOCKER`** | **`File`** / `dev, stg, prod` | **`Environment Secrets`** | Environment file content for Docker. *See `envs/.env.stg.docker.example`*. |
| **`ENV_LARAVEL`** | **`File`** / `dev, stg, prod` | **`Environment Secrets`** | Laravel `.env` content. *See `envs/.env.stg.laravel.example`*. |
| **`ENV_FRONT`** | **`File`** / `dev, stg, prod` | **`Environment Secrets`** | Frontend `.env.local` content. *See `envs/.env.stg.front.example`*. |

---

## 3. Important Notes

1. **Regarding the GitHub Registry (`ghcr.io`):** 
   If using GitHub, the backend Docker image will be pushed to the GitHub Container Registry (`ghcr.io`). You need to update the `.env` file (stored in `ENV_DOCKER`) to:
   `DOCKER_BACK_IMAGE=ghcr.io/<username>/<repo_name>/backend/<branch_name>:latest`

2. **Manual Approval on GitHub:**
   To require manual approval before deploying to `prod` (similar to GitLab's `when: manual`), go to **Settings -> Environments**, select the `prod` environment. Check **Required reviewers** and add authorized reviewers. The deployment process will automatically pause and wait for an "Approve" click.

3. **Docker Base Image:**
   The project has been migrated to use the popular open-source image `webdevops/php-nginx:8.3-alpine`. You no longer need to login or have access to any private registry to pull the base image, ensuring smooth CI/CD execution anywhere.
