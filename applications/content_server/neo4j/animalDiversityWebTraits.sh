#!/bin/bash

cypher-shell -u neo4j -p eli_neo4j -d system "CREATE DATABASE DB.ADWtraits IF NOT EXISTS;"
cypher-shell -u neo4j -p eli_neo4j -d system "STOP DATABASE DB.ADWtraits;"
neo4j-admin database import full DB.ADWtraits --overwrite-destination \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Resource.csv \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Page.csv \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Vernacular.csv \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Term.csv \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Trait.csv \
--nodes=import2/AnimalDiversityWeb_TraitBank_1_0_csv/nodes/Metadata.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/PARENT.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/VERNACULAR.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/TRAIT.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/INFERRED_TRAIT.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/PREDICATE.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/PREDICATE_META_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/OBJECT_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/NORMAL_UNITS_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/UNITS_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/OBJECT_PAGE.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/DETERMINED_BY.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/CONTRIBUTOR.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/LIFESTAGE_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/SEX_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/STATISTICAL_METHOD_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/METADATA.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/PARENT_TERM.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/SYNONYM_OF.csv \
--relationships=import2/AnimalDiversityWeb_TraitBank_1_0_csv/edges/SUPPLIER.csv \
--schema=import2/schema.cypher \
--multiline-fields=true
cypher-shell -u neo4j -p eli_neo4j -d system "START DATABASE DB.ADWtraits;"
