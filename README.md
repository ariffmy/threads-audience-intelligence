# Threads Audience Intelligence

Personal application built with native PHP 8.5 and PDO. Phase 1 provides secure administrator access; Phase 2 adds a bounded MariaDB queue and privacy controls. Phase 3B adds fixture-only Threads OAuth, encrypted credentials, scoped owned-discussion imports and deletion/restore suppression. Live collection and AI analysis remain unavailable.

- [Local setup and testing](docs/LOCAL_SETUP.md)
- [Phase 1 completion report](docs/PHASE1_COMPLETION_REPORT.md)
- [Phase 2 completion report](docs/PHASE2_COMPLETION_REPORT.md)
- [Phase 3A research and decision report](docs/PHASE3A_DECISION_REPORT.md)
- [Phase 3B completion report](docs/PHASE3B_COMPLETION_REPORT.md)
- [Threads OAuth and credentials](docs/THREADS_OAUTH_IMPLEMENTATION.md)
- [Threads collection and privacy](docs/THREADS_DATA_COLLECTION.md)
- [Threads security review](docs/THREADS_SECURITY_REVIEW.md)
- [Remaining staging and provider gates](docs/THREADS_STAGING_TEST_PLAN.md)
- [Queue architecture](docs/QUEUE_ARCHITECTURE.md)
- [CLI and proposed cron setup](docs/CRON_SETUP.md)
- [Privacy and retention](docs/PRIVACY_RETENTION.md)
- [Project scope](docs/PROJECT_OVERVIEW.md)
- [Architecture](docs/SYSTEM_ARCHITECTURE.md)
- [Database design](docs/DATABASE_DESIGN.md)
- [API feasibility and access gates](docs/API_FEASIBILITY.md)
- [Development phases](docs/DEVELOPMENT_PHASES.md)
- [Future cPanel deployment plan](docs/DEPLOYMENT_PLAN.md)

Serve only `public/`. Keep configuration and storage private. No default administrator credentials are supplied. No package installation or build step is required. Stop after Phase 3B review. No live APIs, external AI processing, production access, deployment, push or merge is authorized. Tests use synthetic data and local marked databases.
