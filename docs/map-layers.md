# Map backgrounds and layers

Both maps (the filter map and the points-of-interest map of a unité urbaine)
use the OpenFreeMap vector style by default. A control at the top right
switches the background to the IGN aerial imagery or the IGN plan, and
overlays the forest cover map (BD Forêt) and the regional natural parks.

- The backgrounds and overlays are raster tiles from the IGN Géoplateforme
  (open data, no key), drawn under the labels and under the app's own layers.
- The forest and park overlays only exist from zoom 6.
- The forest map tells broadleaf, conifer and mixed stands apart; it does not
  outline state forests. The parks are perimeters, not forest.
- The choice is kept in the browser (local storage), shared by both maps. The
  IGN servers are only contacted once a background or overlay is selected.
