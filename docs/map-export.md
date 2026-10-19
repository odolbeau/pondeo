# Map export

The filter map (`/carte`) can export the unités urbaines matching its filters
as a CSV file, to work on them in a spreadsheet.

- The export is capped at 200 unités urbaines, so it cannot be used to download
  the whole database. Above the cap, the button is disabled and asks to narrow
  the filters; the server refuses the request too (HTTP 422).
- It applies exactly the filters of the map, including the property type of the
  price filter.
- The columns are the INSEE code, the name, the département and the population,
  then the value of each filter that is set: shop density, restaurant density,
  price per m², and the best travel time (car to the station plus train) to
  each chosen destination. Rows are ordered by decreasing population.
- The file is UTF-8 with a byte order mark and uses `;` as separator and a
  decimal comma, so it opens correctly in a French spreadsheet. A name that
  would be read as a formula is prefixed with an apostrophe.
