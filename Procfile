# Railway process types.
#
# 'web' is what Railway runs by default for the primary service — it's
# listed here explicitly so Nixpacks doesn't have to guess. $PORT is
# injected by Railway at runtime.
#
# 'worker' MUST be run as a SEPARATE Railway service pointed at this same
# repo, with its start command overridden to use this process type (see
# DEPLOYMENT.md, "Queue worker service"). Without it, jobs dispatched via
# SendTransferNotification::dispatch() sit in the 'jobs' table forever and
# never send — dispatching does not process a job, a worker does.
#
# --tries=3: a job that fails 3 times moves to failed_jobs instead of
# retrying forever. --sleep=3: poll interval when the queue is empty, to
# avoid hammering the DB.
web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work database --tries=3 --sleep=3 --timeout=90
