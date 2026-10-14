# POI categories

The points of interest (POIs) are filed in categories, which form a shallow
tree: a category may have a parent. The tree may be as deep as needed. The detail page of an urban unit groups its
POIs under the root of their category (see [urban-unit-detail.md](urban-unit-detail.md)),
and `/categories-poi`, linked from the footer, shows the tree.

## Where the tree is defined

`resources/poi-categories.yaml` is the single place where a category's label
and its place in the tree are set. A category is identified by its `code`,
which the imports look it up by: it must not change.

`bin/console app:import:poi-categories` applies the file: it creates the
missing categories and updates the label and parent of the others in place. It
runs on every deployment, before the imports, so editing the tree is just a
commit: the POIs are not touched and need no re-import. A category the file no
longer lists is left alone, as POIs may still point to it.

A new kind of POI needs its categories added to the file before its import is
written: an import fails if the category it files its POIs in is missing.
