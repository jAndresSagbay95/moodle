CREATE TABLE IF NOT EXISTS integration_requests (
    id uuid PRIMARY KEY,
    actor char(64) NOT NULL,
    idempotency_key uuid NOT NULL,
    workstream text NOT NULL CHECK (workstream IN ('integracion','doctorado','tutoria','mision_down')),
    topic varchar(160) NOT NULL,
    objective varchar(2000) NOT NULL,
    course_ref char(64),
    status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','completed_demo')),
    result jsonb,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (actor,idempotency_key),
    CHECK ((status='pending' AND result IS NULL) OR (status='completed_demo' AND result IS NOT NULL))
);
ALTER TABLE integration_requests ADD COLUMN IF NOT EXISTS course_ref char(64);
ALTER TABLE integration_requests ADD COLUMN IF NOT EXISTS scenario_id varchar(80);
ALTER TABLE integration_requests ADD COLUMN IF NOT EXISTS last_error jsonb;
ALTER TABLE integration_requests DROP CONSTRAINT IF EXISTS integration_requests_status_check;
ALTER TABLE integration_requests ADD CONSTRAINT integration_requests_status_check CHECK (status IN ('pending','completed_demo','completed_mock'));
ALTER TABLE integration_requests DROP CONSTRAINT IF EXISTS integration_requests_check;
ALTER TABLE integration_requests DROP CONSTRAINT IF EXISTS integration_requests_state_result_check;
ALTER TABLE integration_requests ADD CONSTRAINT integration_requests_state_result_check CHECK (
    (status='pending' AND result IS NULL) OR (status IN ('completed_demo','completed_mock') AND result IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS integration_requests_actor_date ON integration_requests(actor,created_at DESC);
CREATE INDEX IF NOT EXISTS integration_requests_actor_course_date ON integration_requests(actor,course_ref,created_at DESC);
CREATE TABLE IF NOT EXISTS integration_events (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    request_id uuid NOT NULL REFERENCES integration_requests(id) ON DELETE CASCADE,
    event text NOT NULL CHECK (event IN ('created','demo_completed')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (request_id,event)
);
ALTER TABLE integration_events DROP CONSTRAINT IF EXISTS integration_events_event_check;
ALTER TABLE integration_events ADD CONSTRAINT integration_events_event_check CHECK (event IN ('created','demo_completed','mock_completed','mock_failed'));

-- Sesiones del motor real, con su estado y evidencia independientes del flujo MOCK.
CREATE TABLE IF NOT EXISTS down_sessions (
    id uuid PRIMARY KEY,
    actor char(64) NOT NULL,
    course_ref char(64) NOT NULL,
    idempotency_key uuid NOT NULL,
    student_id varchar(37) NOT NULL,
    game_type text NOT NULL CHECK (game_type IN ('recognize_choose','relate_associate','order_sequence')),
    preferences jsonb NOT NULL DEFAULT '{}',
    versions jsonb,
    trial_limit integer NOT NULL DEFAULT 20,
    status text NOT NULL DEFAULT 'starting' CHECK (status IN ('starting','active','completed')),
    last_seq integer NOT NULL DEFAULT 0,
    model_state jsonb,
    last_decision jsonb,
    pending_event jsonb,
    last_error jsonb,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE (actor,idempotency_key),
    CHECK ((status='starting' AND model_state IS NULL) OR (status<>'starting' AND model_state IS NOT NULL))
);
CREATE INDEX IF NOT EXISTS down_sessions_actor_course ON down_sessions(actor,course_ref,created_at DESC);
CREATE TABLE IF NOT EXISTS down_trials (
    session_id uuid NOT NULL REFERENCES down_sessions(id) ON DELETE CASCADE,
    event_id uuid NOT NULL,
    seq integer NOT NULL CHECK (seq>=0),
    event jsonb NOT NULL,
    response jsonb NOT NULL,
    elapsed_ms integer NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (session_id,event_id),
    UNIQUE (session_id,seq)
);
