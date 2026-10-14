# src/Service

A service is named after what it does, with a suffix derived from a verb:
`Importer`, `Provider`, `Downloader`, `Finder`, `Resolver`, `Calculator`,
`Deriver`. Not a bare noun or a generic `Service` / `Manager` / `Helper`
(`CriterionSeriesProvider`, not `CriterionSeriesService`).

The prefix says what it acts on (`PopulationSourceDownloader`,
`UniteUrbaineCriteriaProvider`).

This applies to classes that do work. The value objects and DTOs that sit next
to them (`CriterionTrend`, `CriterionDetail`) are plain nouns.
