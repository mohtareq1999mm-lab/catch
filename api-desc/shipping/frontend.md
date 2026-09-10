# Frontend - Shipping Feature

## Status

Admin SPA consumes these endpoints for shipping zone management.

## Consumption

```javascript
export const shippingApi = {
  // Countries
  listCountries(params)        // GET /api/v1/countries?search=&status=&per_page=
  getCountry(id)               // GET /api/v1/countries/{id}
  createCountry(data)          // POST /api/v1/countries
  updateCountry(id, data)      // PUT /api/v1/countries/{id}
  deleteCountry(id)            // DELETE /api/v1/countries/{id}
  getCountryGovernorates(id)   // GET /api/v1/countries/{id}/governorates
  bulkStatusCountries(ids, st) // POST /api/v1/countries/change-status

  // Governorates
  listGovernorates(params)     // GET /api/v1/governorates?search=&status=&country_id=
  getGovernorate(id)           // GET /api/v1/governorates/{id}
  createGovernorate(data)      // POST /api/v1/governorates
  updateGovernorate(id, data)  // PUT /api/v1/governorates/{id}
  deleteGovernorate(id)        // DELETE /api/v1/governorates/{id}
  getGovernorateCities(id)     // GET /api/v1/governorates/{id}/cities
  bulkStatusGovernorates(...)  // PUT /api/v1/governorates/change-status
  toggleFastShipping(id, flag) // PUT /api/v1/governorates/{id}/fast-shipping

  // Cities
  listCities(params)           // GET /api/v1/cities?search=&governorate_id=
  getCity(id)                  // GET /api/v1/cities/{id}
  createCity(data)             // POST /api/v1/cities
  updateCity(id, data)         // PUT /api/v1/cities/{id}
  deleteCity(id)               // DELETE /api/v1/cities/{id}
}
```

## Expected Frontend Components

```
CountriesListPage.vue       → countries CRUD table
CountryFormModal.vue        → create/edit country
GovernoratesListPage.vue    → governorates CRUD table
GovernorateFormModal.vue    → create/edit governorate w/ nested shipping price
CitiesListPage.vue          → cities CRUD table
CityFormModal.vue           → create/edit city
ShippingZoneManager.vue     → hierarchical navigation (Country → Gov → City)
FastShippingToggle.vue      → toggle switch for governorate fast shipping
```
