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

// AppSettings is a singleton config node (id: 'singleton') and AuditEvent rows are
// keyed by their own generated id — both loaded/merged the same way as AppUser.id,
// so both get the same backing uniqueness constraint.
CREATE CONSTRAINT appsettings_id_unique IF NOT EXISTS FOR (s:AppSettings) REQUIRE s.id IS UNIQUE;

CREATE CONSTRAINT auditevent_id_unique IF NOT EXISTS FOR (a:AuditEvent) REQUIRE a.id IS UNIQUE;

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
