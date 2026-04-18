CREATE CONSTRAINT FOR ( metadata:MetaData ) REQUIRE (metadata.eol_pk) IS UNIQUE;

CREATE CONSTRAINT FOR ( page:Page ) REQUIRE (page.page_id) IS UNIQUE;

CREATE CONSTRAINT FOR ( resource:Resource ) REQUIRE (resource.resource_id) IS UNIQUE;

// CREATE CONSTRAINT FOR ( term:Term ) REQUIRE (term.eol_id) IS UNIQUE;
// CREATE CONSTRAINT FOR ( term:Term ) REQUIRE (term.uri) IS UNIQUE;
CREATE CONSTRAINT FOR ( term:Term ) REQUIRE (term.eol_id, term.uri) IS UNIQUE;

CREATE CONSTRAINT FOR ( trait:Trait ) REQUIRE (trait.eol_pk) IS UNIQUE;

// CREATE INDEX FOR (n:Term) ON (n.name);
// CREATE INDEX FOR (n:Trait) ON (n.object_page_id);
// CREATE INDEX FOR (n:Trait) ON (n.resource_pk)
CREATE INDEX FOR (n:Term) ON (n.name, n.object_page_id, n.resource_pk);
