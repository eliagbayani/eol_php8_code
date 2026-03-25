#!/bin/bash

cypher-shell -u neo4j -p eli_neo4j -d system "CREATE DATABASE DB.EOL IF NOT EXISTS;"
cypher-shell -u neo4j -p eli_neo4j -d system "STOP DATABASE DB.EOL;"
neo4j-admin database import full DB.EOL --overwrite-destination \
--nodes=import2/combined_CSVs/nodes/Resource.csv \
--nodes=import2/combined_CSVs/nodes/Page.csv \
--nodes=import2/combined_CSVs/nodes/Vernacular.csv \
--nodes=import2/combined_CSVs/nodes/Term.csv \
--nodes=import2/combined_CSVs/nodes/Trait.csv \
--nodes=import2/combined_CSVs/nodes/Metadata.csv \
--relationships=import2/combined_CSVs/edges/PARENT.csv \
--relationships=import2/combined_CSVs/edges/VERNACULAR.csv \
--relationships=import2/combined_CSVs/edges/TRAIT.csv \
--relationships=import2/combined_CSVs/edges/INFERRED_TRAIT.csv \
--relationships=import2/combined_CSVs/edges/PREDICATE.csv \
--relationships=import2/combined_CSVs/edges/PREDICATE_META_TERM.csv \
--relationships=import2/combined_CSVs/edges/OBJECT_TERM.csv \
--relationships=import2/combined_CSVs/edges/NORMAL_UNITS_TERM.csv \
--relationships=import2/combined_CSVs/edges/UNITS_TERM.csv \
--relationships=import2/combined_CSVs/edges/OBJECT_PAGE.csv \
--relationships=import2/combined_CSVs/edges/DETERMINED_BY.csv \
--relationships=import2/combined_CSVs/edges/CONTRIBUTOR.csv \
--relationships=import2/combined_CSVs/edges/LIFESTAGE_TERM.csv \
--relationships=import2/combined_CSVs/edges/SEX_TERM.csv \
--relationships=import2/combined_CSVs/edges/STATISTICAL_METHOD_TERM.csv \
--relationships=import2/combined_CSVs/edges/METADATA.csv \
--relationships=import2/combined_CSVs/edges/PARENT_TERM.csv \
--relationships=import2/combined_CSVs/edges/SYNONYM_OF.csv \
--relationships=import2/combined_CSVs/edges/SUPPLIER.csv \
--multiline-fields=true
cypher-shell -u neo4j -p eli_neo4j -d system "START DATABASE DB.EOL;"
