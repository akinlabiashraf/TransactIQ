# TransactIQ — Docker Containerization Guide for Beginners

> **A Plain-English Walkthrough for Containerized Financial Infrastructure**  
> *Stage 12 of the TransactIQ Production Roadmap*

---

## 1. What is Docker? (In Simple Words)

Normally, to run TransactIQ, your computer needs:
- PHP 8.4 with specific extensions (`pdo_pgsql`, `redis`, `bcmath`)
- PostgreSQL 16 server running on port 5432
- Redis server running on port 6379
- Node.js 20 and npm for compiling the React frontend
- Web server (Nginx or Apache) to serve HTTP traffic

If you move to a new laptop, or deploy to an Amazon Web Services (AWS) cloud server, you would normally have to manually install and configure all 5 pieces of software again. If any version differs slightly, the code might break ("It worked on my machine!").

**Docker solves this by packaging each piece into a lightweight, isolated "box" called a container.**  
Instead of installing PHP, Postgres, Redis, and Node onto your physical computer's operating system, Docker runs them inside standardized virtual containers that behave identically on Windows, Linux, macOS, and AWS.

---

## 2. The TransactIQ Multi-Container Stack

When you run TransactIQ via Docker, [docker-compose.yml](file:///c:/TransactIQ/docker-compose.yml) starts **6 cooperating containers** on an isolated internal network:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          TRANSACTIQ DOCKER TOPOLOGY                         │
│                                                                             │
│   Incoming Browser Traffic                                                  │
│   (Port 5173 / Port 8000)                                                   │
│             │                                                               │
│             ├──────────────────────────┐                                    │
│             ▼                          ▼                                    │
│   ┌───────────────────┐      ┌───────────────────┐                          │
│   │ transactiq-       │      │ transactiq-       │                          │
│   │ frontend          │      │ backend-web       │                          │
│   │ (React 19 + Nginx)│      │ (Nginx Reverse    │                          │
│   │ Port 5173         │      │  Proxy) Port 8000 │                          │
│   └───────────────────┘      └─────────┬─────────┘                          │
│                                        │ FastCGI                            │
│                                        ▼                                    │
│                              ┌───────────────────┐    Queue Jobs            │
│                              │ transactiq-       │───────────────────┐      │
│                              │ backend           │                   ▼      │
│                              │ (PHP 8.4-FPM Core)│         ┌───────────────────┐
│                              └─────────┬─────────┘         │ transactiq-queue  │
│                                        │                   │ (Worker Daemon)   │
│                   ┌────────────────────┴───────────┐       └─────────┬─────────┘
│                   ▼                                ▼                 │
│         ┌───────────────────┐            ┌───────────────────┐       │
│         │ transactiq-       │            │ transactiq-redis  │◄──────┘
│         │ postgres          │            │ (Redis 7 In-Memory│
│         │ (PostgreSQL 16)   │            │  Locks & Queues)  │
│         └───────────────────┘            └───────────────────┘
└─────────────────────────────────────────────────────────────────────────────┘
```

1. **`transactiq-postgres`**: PostgreSQL 16 database storing tables, transactions, accounts, and audit records with persistent hard-drive storage.
2. **`transactiq-redis`**: High-speed memory store managing distributed idempotency locks (`SET NX EX 10`) and background job queues.
3. **`transactiq-backend`**: PHP 8.4-FPM running the Laravel transaction state machine, double-entry financial ledger, and security checks.
4. **`transactiq-backend-web`**: Nginx web server listening on port `8000` that receives API calls and passes them to PHP FastCGI.
5. **`transactiq-queue`**: Dedicated background worker container executing `php artisan queue:work` to send HMAC webhooks and compute settlements asynchronously.
6. **`transactiq-frontend`**: Nginx container serving the compiled React 19 dashboard on port `5173`.

---

## 3. The Commands You Will Use

You only need **4 essential commands**:

### Command 1: Start the Entire Stack
```bash
docker compose up -d
```
- `up`: Builds images and starts all 6 containers.
- `-d` (*detached mode*): Runs them in the background so your terminal remains free.

### Command 2: Check Container Health
```bash
docker compose ps
```
- Shows the running status and health check (`healthy`) for each container.

### Command 3: Run Database Migrations & Tests Inside Containers
```bash
# Run database migrations and seeds inside the container
docker compose exec backend php artisan migrate --seed

# Run the 60 automated tests inside the container
docker compose exec backend php artisan test
```
- `exec backend`: Means "execute this command inside the `backend` container".

### Command 4: Stop the Stack
```bash
docker compose down
```
- Safely stops all containers. Your database data is preserved in the Docker persistent volume (`postgres_data`).

---

## 4. Why This Prepares You for AWS (Stage 14)

On your AWS EC2 cloud server (Stage 14), we will **not** need to manually install PHP, PostgreSQL, or Redis on Linux.  
Instead, we will install Docker on the EC2 machine with **one single command**, clone this repository, and run `docker compose up -d`. The identical containers that ran locally will start on AWS instantly!
