# Address Module — Request Flow Diagrams

## 1. List Addresses

```
Client                  Routes.php            AddressController       AddressRepository       DB
  │                         │                       │                       │                  │
  │──── GET /address ──────>│                       │                       │                  │
  │                         │── index(Request) ────>│                       │                  │
  │                         │                       │── where(customer_id) ─>│                  │
  │                         │                       │── get() ──────────────>│                  │
  │                         │                       │                       │── SELECT * ──────>│
  │                         │                       │                       │<── collection ────│
  │                         │                       │<── Collection ────────│                  │
  │                         │<── JSON 200 ──────────│                       │                  │
  │<── JSON 200 ────────────│                       │                       │                  │
```

## 2. Create Address

```
Client                  Routes.php            AddressController     AddressRequest       AddressRepository       DB
  │                         │                       │                       │                  │                  │
  │──── POST /address ─────>│                       │                       │                  │                  │
  │   {title, type, ...}   │── store(AddressReq) ──>│                       │                  │                  │
  │                         │                       │── validate() ────────>│                  │                  │
  │                         │                       │<── validated ─────────│                  │                  │
  │                         │                       │                       │                  │                  │
  │                         │                       │── merge(customer_id)  │                  │                  │
  │                         │                       │── create(data) ───────>│                  │                  │
  │                         │                       │                       │── INSERT ────────>│                  │
  │                         │                       │                       │<── Address ───────│                  │
  │                         │                       │<── AddressResource ───│                  │                  │
  │                         │<── JSON 201 ──────────│                       │                  │                  │
  │<── JSON 201 ────────────│                       │                       │                  │                  │
```

## 3. Get Address

```
Client                  Routes.php            AddressController       AddressRepository       DB
  │                         │                       │                       │                  │
  │──── GET /address/1 ────>│                       │                       │                  │
  │                         │── show(1) ───────────>│                       │                  │
  │                         │                       │── where(customer_id) ─>│                  │
  │                         │                       │── find(1) ───────────>│                  │
  │                         │                       │                       │── SELECT ────────>│
  │                         │                       │                       │<── Address ───────│
  │                         │                       │<── AddressResource ───│                  │
  │                         │<── JSON 200 ──────────│                       │                  │
  │<── JSON 200 ────────────│                       │                       │                  │
```

## 4. Update Address

```
Client                  Routes.php            AddressController     AddressRequest       AddressRepository       DB
  │                         │                       │                       │                  │                  │
  │──── PUT /address/1 ────>│                       │                       │                  │                  │
  │   {title, type, ...}   │── update(AddressReq,1) >│                      │                  │                  │
  │                         │                       │── validate() ────────>│                  │                  │
  │                         │                       │<── validated ─────────│                  │                  │
  │                         │                       │                       │                  │                  │
  │                         │                       │── except(customer_id) │                  │                  │
  │                         │                       │── where(cust_id) ─────>│                  │                  │
  │                         │                       │── find(1) ───────────>│                  │                  │
  │                         │                       │                       │── SELECT ────────>│                  │
  │                         │                       │                       │<── Address ───────│                  │
  │                         │                       │── update(validated) ──>│                  │                  │
  │                         │                       │                       │── UPDATE ────────>│                  │
  │                         │                       │                       │<── done ──────────│                  │
  │                         │                       │<── AddressResource ───│                  │                  │
  │                         │<── JSON 200 ──────────│                       │                  │                  │
  │<── JSON 200 ────────────│                       │                       │                  │                  │
```

## 5. Delete Address

```
Client                  Routes.php            AddressController       AddressRepository       DB
  │                         │                       │                       │                  │
  │─── DELETE /address/1 ──>│                       │                       │                  │
  │                         │── destroy(1, Req) ───>│                       │                  │
  │                         │                       │── where(customer_id) ─>│                  │
  │                         │                       │── find(1) ───────────>│                  │
  │                         │                       │                       │── SELECT ────────>│
  │                         │                       │                       │<── Address ───────│
  │                         │                       │── delete() ──────────>│                  │
  │                         │                       │                       │── DELETE ────────>│
  │                         │                       │                       │<── done ──────────│
  │                         │<── JSON 200 ──────────│                       │                  │
  │<── JSON 200 ────────────│                       │                       │                  │
```
