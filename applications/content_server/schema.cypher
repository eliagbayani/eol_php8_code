// Run against the `neo4j` database as part of every reload/rebuild, before the
// app is started — creates the constraints/indexes both the existing
// EOL/TraitBank dataset and this app (server/) rely on. All statements are
// `IF NOT EXISTS`, so safe to re-run.
//
// The AppUser.email / pageSearchFulltext lines are this app's own
// additions — they MUST stay in sync with server/src/neo4j/ensureSchema.ts,
// which creates the exact same statements automatically on every server
// boot as a backstop (see ../README.md's "Database reloads wipe AppUser
// data"). If either changes, change the other to match — this file being
// the primary fix (runs as part of the reload itself, before the app ever
// sees a moment without them), ensureSchema.ts the defense-in-depth backstop
// for a reload that runs without this file, or a fresh dev database.

CREATE CONSTRAINT IF NOT EXISTS FOR (metadata:Metadata) REQUIRE metadata.eol_pk IS UNIQUE;

CREATE CONSTRAINT IF NOT EXISTS FOR (page:Page) REQUIRE page.page_id IS UNIQUE;

CREATE CONSTRAINT IF NOT EXISTS FOR (resource:Resource) REQUIRE resource.resource_id IS UNIQUE;

CREATE CONSTRAINT IF NOT EXISTS FOR (term:Term) REQUIRE term.uri IS UNIQUE;

CREATE CONSTRAINT IF NOT EXISTS FOR (trait:Trait) REQUIRE trait.eol_pk IS UNIQUE;

CREATE CONSTRAINT appuser_id_unique IF NOT EXISTS FOR (user:AppUser) REQUIRE user.id IS UNIQUE;

CREATE INDEX IF NOT EXISTS FOR (n:Term) ON (n.name);

CREATE INDEX IF NOT EXISTS FOR (t:Trait) ON (t.object_page_id);

CREATE INDEX IF NOT EXISTS FOR (t:Trait) ON (t.resource_pk);

// --- Added for the traitbank-1-0 app (server/) — see comment above ---
// register()/create-admin.ts enforce email uniqueness in the app layer, but
// this constraint is the real guarantee — without it, a data-level path
// (or a future bug) could produce two AppUser rows with the same email.
CREATE CONSTRAINT appuser_email_unique IF NOT EXISTS FOR (user:AppUser) REQUIRE user.email IS UNIQUE;

// Belt-and-suspenders against a hash collision between two accounts' API
// tokens (server/src/data/userStore.ts's generateApiToken/createUser) —
// see ensureSchema.ts's own comment on this same constraint.
CREATE CONSTRAINT appuser_api_token_hash_unique IF NOT EXISTS FOR (user:AppUser) REQUIRE user.apiTokenHash IS UNIQUE;

// Backs pagesByCanonical (the canonical-name and vernacular-name search,
// server/src/data/searchStore.ts — a hand-written resolver, not a
// @fulltext-directive-backed one; see the comment on `type Page` in
// server/src/graphql/schema/page.graphql for why). A single combined
// multi-label index rather than two separate ones: VernacularPageID has no
// `canonical` property and Page has no `vernacularName` property, so each
// label is simply not indexed for the field it lacks — no error, no
// special-casing. Superseded pageCanonicalFulltext (Page.canonical only),
// which is dropped by name first since fulltext indexes can't be redefined
// in place; on a fresh/reloaded DB that DROP is a no-op.
DROP INDEX pageCanonicalFulltext IF EXISTS;

CREATE FULLTEXT INDEX pageSearchFulltext IF NOT EXISTS FOR (n:Page|VernacularPageID) ON EACH [n.canonical, n.vernacularName];

// Backs every point lookup by page_id against VernacularPageID (the taxon page's
// vernacular-name heading, and every graph node label — Relationships/Trophic Web —
// see server/src/data/taxonStore.ts's getVernacularNames). Without this,
// MATCH (v:VernacularPageID {page_id: pid}) is a full label scan; caught live when a
// batched lookup (UNWIND over ~100-240 page_ids for one graph load) turned into that
// many full scans of all 239,514 VernacularPageID nodes in a single query and
// effectively hung. A plain range index, unlike pageSearchFulltext above, needs no
// special DROP-first handling — it can just be created.
CREATE INDEX vernacular_page_id_idx IF NOT EXISTS FOR (v:VernacularPageID) ON (v.page_id);

// Backs auditStore.ts's listAuditEvents (`MATCH (e:AuditEvent) RETURN e ORDER
// BY e.createdAt DESC LIMIT $limit`, the Admin Utilities Audit Log tab's
// query). Without this it's a full label scan + sort on every load, growing
// worse as events accumulate — this app logs a lot of them: every login
// attempt, every query execution/rejection/rate-limit, every cache clear,
// every role change. A plain range index, same as vernacular_page_id_idx
// above — no wait-for-online polling needed.
CREATE INDEX auditevent_created_at_idx IF NOT EXISTS FOR (e:AuditEvent) ON (e.createdAt);

// Backs restoreAuditEvents' existence-check query (see
// server/src/data/backupAuditEventStore.ts) the same way appuser_id_unique
// backs restoreAppUsers' identical-shaped check — see ensureSchema.ts's
// matching comment on this constraint for why it's now a real guarantee,
// not just a low-probability one.
CREATE CONSTRAINT auditevent_id_unique IF NOT EXISTS FOR (e:AuditEvent) REQUIRE e.id IS UNIQUE;

// --- PROPOSED for the externally-managed EOL/TraitBank dataset ---
// NOT YET APPLIED. These are recommendations for whoever runs the reload
// pipeline to review and decide on — this app doesn't own or execute this
// part of the schema, and none of these statements run anywhere (not here,
// not in ensureSchema.ts). Confirmed against the app's actual query patterns
// (server/src/data/*Store.ts) as of 2026-09, at today's ~2.5M-node scale;
// worth re-evaluating once the dataset reaches its ~82.6M-node target
// (project-scope.md), since traversal fan-out will grow with it.

// [1] VernacularPageID.page_id currently only has a plain range index
// (vernacular_page_id_idx above) backing exact-match lookups. If each Page
// is guaranteed exactly one VernacularPageID node (1:1), upgrading this to a
// uniqueness constraint would both document that guarantee and back the same
// lookups at least as well. Verify cardinality first:
// MATCH (v:VernacularPageID)
// WITH v.page_id AS pid, count(*) AS c
// WHERE c > 1
// RETURN count(*) AS duplicates
// If that returns 0:
// CREATE CONSTRAINT vernacular_page_id_unique IF NOT EXISTS FOR (v:VernacularPageID) REQUIRE v.page_id IS UNIQUE;
// - it has duplicates and it will remain that way

// [2] Resource.name has no index — sorted on in taxonStore.ts's
// getProviderOptions (`ORDER BY name`) and as the "Provider" sort field on
// the Traits table. Currently always reached by traversing from one Page's
// Traits first (small, already-narrowed fan-out), so likely not a bottleneck
// yet — worth adding if provider filtering/sorting is ever exposed at a
// broader scope than "one taxon's traits," or once the fuller dataset makes
// per-page provider fan-out large enough to matter.
CREATE INDEX resource_name_idx IF NOT EXISTS FOR (r:Resource) ON (r.name);

// [3] Term is only indexed on `name`. Several queries filter on `type` and
// `name` together (predation-predicate matching in trophicWebStore.ts,
// attribute/value Term resolution in taxonStore.ts's getTraitRecords). A
// composite index could help once these stop being Page-traversal-scoped
// (i.e. a small fan-out per query) — not clearly needed today.
CREATE INDEX term_name_type_idx IF NOT EXISTS FOR (t:Term) ON (t.name, t.type);
