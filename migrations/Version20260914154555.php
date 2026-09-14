<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260914154555 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: administrative zones (region/departement/epci/commune), the generic criterion/zone_criterion_value model, and POI/poi_category/poi_commune_location.';
    }

    public function up(Schema $schema): void
    {
        // --- Hand-added: Doctrine's schema tool has no notion of Postgres
        // extensions, so this is never auto-generated (see plan §6).
        $this->addSql('CREATE EXTENSION IF NOT EXISTS postgis');

        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE commune (insee_code VARCHAR(5) NOT NULL, name VARCHAR(255) NOT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, centroid geography(POINT, 4326) GENERATED ALWAYS AS (ST_Centroid(boundary)::geography) STORED, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, departement_id UUID NOT NULL, region_id UUID NOT NULL, epci_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2E2D1EE15A3C1BC ON commune (insee_code)');
        $this->addSql('CREATE INDEX IDX_E2E2D1EECCF9E01E ON commune (departement_id)');
        $this->addSql('CREATE INDEX IDX_E2E2D1EE98260155 ON commune (region_id)');
        $this->addSql('CREATE INDEX IDX_E2E2D1EE4E7C18CB ON commune (epci_id)');
        $this->addSql('CREATE TABLE criterion (code VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, unit VARCHAR(50) DEFAULT NULL, value_type VARCHAR(20) NOT NULL, zone_type VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7C82227177153098 ON criterion (code)');
        $this->addSql('CREATE TABLE departement (insee_code VARCHAR(3) NOT NULL, name VARCHAR(255) NOT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, centroid geography(POINT, 4326) GENERATED ALWAYS AS (ST_Centroid(boundary)::geography) STORED, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, region_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C1765B6315A3C1BC ON departement (insee_code)');
        $this->addSql('CREATE INDEX IDX_C1765B6398260155 ON departement (region_id)');
        $this->addSql('CREATE TABLE epci (siren_code VARCHAR(9) NOT NULL, name VARCHAR(255) NOT NULL, epci_type VARCHAR(30) NOT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, centroid geography(POINT, 4326) GENERATED ALWAYS AS (ST_Centroid(boundary)::geography) STORED, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3F351E5E7234D09 ON epci (siren_code)');
        $this->addSql('CREATE TABLE poi (name VARCHAR(255) NOT NULL, brand VARCHAR(255) DEFAULT NULL, location geography(POINT, 4326) NOT NULL, address VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, category_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7DBB1FD612469DE2 ON poi (category_id)');
        $this->addSql('CREATE TABLE poi_category (code VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FC442D077153098 ON poi_category (code)');
        $this->addSql('CREATE INDEX IDX_7FC442D0727ACA70 ON poi_category (parent_id)');
        $this->addSql('CREATE TABLE poi_commune_location (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, poi_id UUID NOT NULL, commune_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_104C75147EACE855 ON poi_commune_location (poi_id)');
        $this->addSql('CREATE INDEX IDX_104C7514131A4F72 ON poi_commune_location (commune_id)');
        $this->addSql('CREATE TABLE region (insee_code VARCHAR(3) NOT NULL, name VARCHAR(255) NOT NULL, boundary geometry(MULTIPOLYGON, 4326) DEFAULT NULL, centroid geography(POINT, 4326) GENERATED ALWAYS AS (ST_Centroid(boundary)::geography) STORED, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F62F17615A3C1BC ON region (insee_code)');
        $this->addSql('CREATE TABLE zone_criterion_value (zone_type VARCHAR(20) NOT NULL, value_decimal NUMERIC(15, 5) DEFAULT NULL, value_integer INT DEFAULT NULL, value_text TEXT DEFAULT NULL, value_boolean BOOLEAN DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, criterion_id UUID NOT NULL, commune_id UUID DEFAULT NULL, epci_id UUID DEFAULT NULL, departement_id UUID DEFAULT NULL, region_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A15758B7977663071E26968C131A4F724E7C18CBCCF9E01E98260155 ON zone_criterion_value (criterion_id, zone_type, commune_id, epci_id, departement_id, region_id)');
        $this->addSql('CREATE INDEX IDX_A15758B797766307 ON zone_criterion_value (criterion_id)');
        $this->addSql('CREATE INDEX IDX_A15758B7131A4F72 ON zone_criterion_value (commune_id)');
        $this->addSql('CREATE INDEX IDX_A15758B74E7C18CB ON zone_criterion_value (epci_id)');
        $this->addSql('CREATE INDEX IDX_A15758B7CCF9E01E ON zone_criterion_value (departement_id)');
        $this->addSql('CREATE INDEX IDX_A15758B798260155 ON zone_criterion_value (region_id)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE commune ADD CONSTRAINT FK_E2E2D1EECCF9E01E FOREIGN KEY (departement_id) REFERENCES departement (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE commune ADD CONSTRAINT FK_E2E2D1EE98260155 FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE commune ADD CONSTRAINT FK_E2E2D1EE4E7C18CB FOREIGN KEY (epci_id) REFERENCES epci (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE departement ADD CONSTRAINT FK_C1765B6398260155 FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poi ADD CONSTRAINT FK_7DBB1FD612469DE2 FOREIGN KEY (category_id) REFERENCES poi_category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poi_category ADD CONSTRAINT FK_7FC442D0727ACA70 FOREIGN KEY (parent_id) REFERENCES poi_category (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poi_commune_location ADD CONSTRAINT FK_104C75147EACE855 FOREIGN KEY (poi_id) REFERENCES poi (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE poi_commune_location ADD CONSTRAINT FK_104C7514131A4F72 FOREIGN KEY (commune_id) REFERENCES commune (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT FK_A15758B797766307 FOREIGN KEY (criterion_id) REFERENCES criterion (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT FK_A15758B7131A4F72 FOREIGN KEY (commune_id) REFERENCES commune (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT FK_A15758B74E7C18CB FOREIGN KEY (epci_id) REFERENCES epci (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT FK_A15758B7CCF9E01E FOREIGN KEY (departement_id) REFERENCES departement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT FK_A15758B798260155 FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE CASCADE NOT DEFERRABLE');

        // --- Hand-added: GiST spatial indexes. `make:migration` didn't
        // generate these because the entities didn't declare them yet at
        // the time this migration was written — they're now declared via
        // `#[ORM\Index(flags: ['spatial'])]` (jsor/doctrine-postgis's
        // native spatial-index support), matching these exact names, so
        // any *future* migration touching these tables will generate GiST
        // indexes automatically; no more hand-editing needed for this.
        $this->addSql('CREATE INDEX commune_boundary_gist ON commune USING GIST (boundary)');
        $this->addSql('CREATE INDEX commune_centroid_gist ON commune USING GIST (centroid)');
        $this->addSql('CREATE INDEX departement_boundary_gist ON departement USING GIST (boundary)');
        $this->addSql('CREATE INDEX departement_centroid_gist ON departement USING GIST (centroid)');
        $this->addSql('CREATE INDEX epci_boundary_gist ON epci USING GIST (boundary)');
        $this->addSql('CREATE INDEX epci_centroid_gist ON epci USING GIST (centroid)');
        $this->addSql('CREATE INDEX region_boundary_gist ON region USING GIST (boundary)');
        $this->addSql('CREATE INDEX region_centroid_gist ON region USING GIST (centroid)');
        $this->addSql('CREATE INDEX poi_location_gist ON poi USING GIST (location)');

        // --- Hand-added: defense-in-depth CHECK constraints mirroring the
        // #[Assert\Callback] validations on ZoneCriterionValue (plan §2.6/§7)
        // — enforced at the DB layer too, since app-level validation can be
        // bypassed by direct SQL, fixtures, or future import scripts.
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT chk_zcv_exactly_one_zone CHECK (num_nonnulls(commune_id, epci_id, departement_id, region_id) = 1)');
        $this->addSql('ALTER TABLE zone_criterion_value ADD CONSTRAINT chk_zcv_exactly_one_value CHECK (num_nonnulls(value_decimal, value_integer, value_text, value_boolean) = 1)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        // (tiger/tiger_data/topology schemas are PostGIS-owned, not ours —
        // not reversed here, see up()'s CREATE EXTENSION comment / plan §6)
        $this->addSql('ALTER TABLE commune DROP CONSTRAINT FK_E2E2D1EECCF9E01E');
        $this->addSql('ALTER TABLE commune DROP CONSTRAINT FK_E2E2D1EE98260155');
        $this->addSql('ALTER TABLE commune DROP CONSTRAINT FK_E2E2D1EE4E7C18CB');
        $this->addSql('ALTER TABLE departement DROP CONSTRAINT FK_C1765B6398260155');
        $this->addSql('ALTER TABLE poi DROP CONSTRAINT FK_7DBB1FD612469DE2');
        $this->addSql('ALTER TABLE poi_category DROP CONSTRAINT FK_7FC442D0727ACA70');
        $this->addSql('ALTER TABLE poi_commune_location DROP CONSTRAINT FK_104C75147EACE855');
        $this->addSql('ALTER TABLE poi_commune_location DROP CONSTRAINT FK_104C7514131A4F72');
        $this->addSql('ALTER TABLE zone_criterion_value DROP CONSTRAINT FK_A15758B797766307');
        $this->addSql('ALTER TABLE zone_criterion_value DROP CONSTRAINT FK_A15758B7131A4F72');
        $this->addSql('ALTER TABLE zone_criterion_value DROP CONSTRAINT FK_A15758B74E7C18CB');
        $this->addSql('ALTER TABLE zone_criterion_value DROP CONSTRAINT FK_A15758B7CCF9E01E');
        $this->addSql('ALTER TABLE zone_criterion_value DROP CONSTRAINT FK_A15758B798260155');
        $this->addSql('DROP TABLE commune');
        $this->addSql('DROP TABLE criterion');
        $this->addSql('DROP TABLE departement');
        $this->addSql('DROP TABLE epci');
        $this->addSql('DROP TABLE poi');
        $this->addSql('DROP TABLE poi_category');
        $this->addSql('DROP TABLE poi_commune_location');
        $this->addSql('DROP TABLE region');
        $this->addSql('DROP TABLE zone_criterion_value');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
