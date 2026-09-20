# Line of business → Revenue Code class

**For BPLO's review.** Generated from `api/app/Support/TaxClassification.php`, which is
the single source of truth — this file is regenerated from it, never edited by hand.

## Why BizTrack needs this

The paper form has an **Assessed Fee** box that a clerk fills in by hand from the
Revenue Code. BizTrack has nobody in that seat, so it has to work out what the
clerk would have determined. Until 16 September 2026 it asked the *applicant* to
classify their own business, from a type-ahead of 273 Revenue Code labels.

That was **mis-billing**, not merely a hard question. Two fee groups key on two
different vocabularies, and the screen offered one box:

- `business_tax` matches the **22 broad classes** of Sec. 2J.02 — retailer,
  wholesaler, manufacturer, contractor, restaurant, bank …
- `mayors_permit` matches the **117 fine categories** of Sec. 3A.03 — carinderia,
  barber shop, cellphone dealer, movie house …

Measured on a carinderia with ₱1,200,000 of gross sales in 45 sq. m.:

| the applicant answered | billed | what happened |
|---|---|---|
| "Carinderia" | **₱2,218.25** | ₱550 permit fee, **no business tax at all** |
| "Restaurant" | **₱11,707.00** | ₱9,750 business tax, ₱450 catch-all permit fee |
| both | ₱11,968.25 | correct — and unreachable through one box |

The **more accurate** answer was the one that lost ₱9,750 — 81% of the bill.

A line of business determines both keys, so both are derived and the applicant is
asked neither.

## What the applicant is asked now

- **117 of 135 codes** — nothing at all.
- **17 codes** — one Yes/No. Sec. 2J.02(c) halves the rate for dealers in
  **essential commodities** and no industrial classification can tell rice from
  radios; a sari-sari store sells both. The essential list is the LGC Sec. 143(c)
  enumeration: rice and corn; flour, meat, dairy, locally manufactured/processed/
  preserved food, sugar, salt and other agricultural, marine and fresh-water
  products; cooking oil and cooking gas; laundry soap, detergents and medicine;
  agricultural implements and post-harvest facilities, fertilisers, pesticides and
  other farm inputs; poultry and other animal feeds; school supplies; cement.
- **1 code** — `00000` "Other (not listed)", where the applicant typed their own
  trade and there is nothing to derive from. The old question is asked for that
  line alone.

> **One approximation to confirm.** A mixed trader is charged ONE rate on the whole
> of their gross. Apportioning essential from non-essential turnover is what the
> Code contemplates, and "mainly" is the approximation — the applicant's own
> declaration, on the record, rather than our guess.

## Open questions — please confirm or correct

A permit category of *item 64 catch-all* is **not an oversight**. Sec. 3A.03 item 64
prices "all other businesses not specifically mentioned" by office area, and that is
correct for retail stores, which have no category of their own. It is also what we
fall back to wherever the Code's fine category cannot be determined from the line of
business alone — because the spreads are wide and a wrong guess bills a real amount
confidently. A hotel is ₱2,200 to ₱11,000 by accredited class; a bar ₱4,400 to
₱16,500 by whether it has VIP rooms and live entertainment; a general building
contractor ₱1,100 to ₱6,600 by CAB class; PSIC 56101 covers both a ₱550 carinderia
and a ₱5,500 restaurant with multiple meal offerings.

**38 of 135** codes carry a fine category today. These are the ones where the
Code has a category and we could not tell which — **please confirm or correct each**:

| PSIC | Line of business | Tax class |
|---|---|---|
| `10300` | Processing and preserving of fruits and vegetables | `manufacturer` |
| `10500` | Manufacture of dairy products | `manufacturer` |
| `10611` | Rice and corn milling | `manufacturer` |
| `10711` | Manufacture of bakery products (bakeshop) | `manufacturer` |
| `10740` | Manufacture of noodles and similar products | `manufacturer` |
| `10799` | Manufacture of other food products (ice plant) | `manufacturer` |
| `10800` | Manufacture of prepared animal feeds | `manufacturer` |
| `11040` | Manufacture of soft drinks and bottled water | `manufacturer` |
| `15200` | Manufacture of footwear | `manufacturer` |
| `16220` | Manufacture of builders' carpentry and joinery | `manufacturer` |
| `17020` | Manufacture of paper and paperboard containers | `manufacturer` |
| `18120` | Printing services | `printing_publication` |
| `20230` | Manufacture of soap, detergents and cleaning preparations | `manufacturer` |
| `23950` | Manufacture of concrete products (hollow blocks) | `manufacturer` |
| `31001` | Manufacture of furniture | `manufacturer` |
| `36000` | Water collection, treatment and supply (water refilling) | `manufacturer` |
| `38110` | Collection of non-hazardous waste | `contractor` |
| `41000` | Construction of buildings (general contractor) | `contractor` |
| `43210` | Electrical installation | `contractor` |
| `43220` | Plumbing, heating and air-conditioning installation | `contractor` |
| `43300` | Building completion and finishing | `contractor` |
| `49221` | Passenger land transport (jeepney, UV express, tricycle) | `puv_operator` |
| `49230` | Freight transport by road (trucking) | `puv_operator` |
| `53100` | Postal and courier activities | `contractor` |
| `55101` | Hotels and resorts | `contractor` |
| `55102` | Apartelles, pension houses and inns | `contractor` |
| `55103` | Motels and lodging houses | `contractor` |
| `56101` | Restaurants and carinderia | `restaurant` |
| `56102` | Fast-food and quick-service restaurants | `restaurant` |
| `56103` | Refreshment stands, kiosks and food carts | `restaurant` |
| `56290` | Other food service activities (canteen and institutional catering) | `restaurant` |
| `56302` | Bars, beer houses and drinking places | `bar_nightclub` |
| `58130` | Publishing of newspapers and periodicals | `printing_publication` |
| `61100` | Wired telecommunications activities | `franchise_holder` |
| `62010` | Computer programming activities | `contractor` |
| `62090` | Other information technology and computer service activities | `contractor` |
| `63110` | Data processing, hosting and related activities | `contractor` |
| `68100` | Lessor of real estate (apartments, stalls, commercial space) | `lessor` |
| `69100` | Legal activities | `contractor` |
| `69200` | Accounting, bookkeeping and auditing activities | `contractor` |
| `70200` | Management consultancy activities | `contractor` |
| `71100` | Architectural and engineering activities | `contractor` |
| `73100` | Advertising | `contractor` |
| `74200` | Photographic activities (photo studio) | `contractor` |
| `77100` | Renting and leasing of motor vehicles (rent-a-car) | `contractor` |
| `77290` | Renting and leasing of other personal and household goods | `contractor` |
| `79110` | Travel agency activities | `contractor` |
| `80100` | Private security activities | `contractor` |
| `82200` | Activities of call centres | `contractor` |
| `82990` | Other business support service activities | `contractor` |
| `86100` | Hospital activities | `contractor` |
| `86201` | Medical and dental clinic activities | `contractor` |
| `92000` | Gambling and betting activities (lotto outlet) | `amusement_place` |
| `93290` | Other amusement and recreation (billiard hall, videoke, internet cafe) | `amusement_place` |
| `95110` | Repair of computers and peripheral equipment | `contractor` |
| `95210` | Repair of consumer electronics | `contractor` |
| `95220` | Repair of household appliances | `contractor` |
| `95230` | Repair of footwear and leather goods | `contractor` |
| `95290` | Repair of other personal and household goods | `contractor` |
| `96120` | Beauty parlour, salon and spa services | `contractor` |

These also fall to item 64, and that is **expected** rather than a question: Sec. 3A.03
has no category for a sari-sari store, a grocery or a general wholesaler, so "all other
businesses not specifically mentioned" is the right answer for them.

| PSIC | Line of business | Tax class |
|---|---|---|
| `45301` | Sale of motor vehicle parts and accessories | `retailer` |
| `45401` | Sale, maintenance and repair of motorcycles | `retailer` |
| `46100` | Wholesale on a fee or contract basis (commission agent) | `wholesaler` |
| `47111` | Retail sale in non-specialized stores (sari-sari store) | `retailer` |
| `47112` | Retail sale in non-specialized stores (grocery or mini-mart) | `retailer` |
| `47190` | Other retail sale in non-specialized stores (department store) | `retailer` |
| `47211` | Retail sale of rice, corn and other grains | `retailer` |
| `47212` | Retail sale of fruits and vegetables | `retailer` |
| `47213` | Retail sale of meat and meat products | `retailer` |
| `47214` | Retail sale of fish and other seafood | `retailer` |
| `47219` | Retail sale of other food products (dry goods) | `retailer` |
| `47220` | Retail sale of beverages | `retailer` |
| `47230` | Retail sale of tobacco products | `retailer` |
| `47411` | Retail sale of computers and peripheral equipment | `retailer` |
| `47420` | Retail sale of audio and video equipment | `retailer` |
| `47510` | Retail sale of textiles | `retailer` |
| `47521` | Retail sale of hardware and building materials | `retailer` |
| `47522` | Retail sale of paints, glass and plumbing supplies | `retailer` |
| `47591` | Retail sale of furniture | `retailer` |
| `47592` | Retail sale of household appliances | `retailer` |
| `47610` | Retail sale of books, newspapers and stationery | `retailer` |
| `47640` | Retail sale of sporting goods | `retailer` |
| `47650` | Retail sale of games and toys | `retailer` |
| `47711` | Retail sale of clothing and apparel | `retailer` |
| `47712` | Retail sale of footwear and leather goods | `retailer` |
| `47721` | Retail sale of pharmaceutical goods (pharmacy) | `retailer` |
| `47722` | Retail sale of medical and orthopaedic goods | `retailer` |
| `47723` | Retail sale of cosmetics and toilet articles | `retailer` |
| `47730` | Retail sale of jewellery and watches | `retailer` |
| `47733` | Retail sale of agricultural supplies, feeds and fertilizers | `retailer` |
| `47741` | Retail sale of second-hand goods (ukay-ukay) | `retailer` |
| `47760` | Retail sale of flowers, plants, pets and pet food | `retailer` |
| `47810` | Retail sale of food products via stalls and markets | `retailer` |
| `47820` | Retail sale of textiles and footwear via stalls and markets | `retailer` |
| `47912` | Retail sale via internet (online store) | `retailer` |
| `47990` | Other retail sale not in stores (direct selling) | `retailer` |

## Two things this mapping cannot reach

**40 of the 165 mayor's-permit rules are unreachable** whatever is mapped here,
because they key on facts BizTrack never collects: `office_location` (35 rules),
`goods_class` (32), `warehouse_location` (24), `factory_location` (4). That is why a
manufacturer can only ever match the ₱6,050 "multiple products" rule and never the
₱4,400–₱8,800 ones that name what they handle. Collecting those four is a separate
decision — it would add four questions back to the wizard.

**Gross sales cannot be derived.** It is a fact only the business knows. The Code's
own fallback where nobody can produce it is the Presumptive Income Level applied by
the City Treasurer (Sec. 2O), not something BizTrack can compute.

## The full mapping

| PSIC | Line of business | Tax class | Tax | Permit category | Permit fee | Asks |
|---|---|---|---|---|---|---|
| `00000` | Other (not listed) | `—` |  | `item 64 catch-all` | by office area |  |
| `10300` | Processing and preserving of fruits and vegetables | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `10500` | Manufacture of dairy products | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `10611` | Rice and corn milling | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `10711` | Manufacture of bakery products (bakeshop) | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `10740` | Manufacture of noodles and similar products | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `10799` | Manufacture of other food products (ice plant) | `manufacturer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `10800` | Manufacture of prepared animal feeds | `essential_manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `11040` | Manufacture of soft drinks and bottled water | `manufacturer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `14100` | Manufacture of wearing apparel (garments and tailoring) | `manufacturer` | graduated | `manufacturer_small_scale` | ₱3,300 |  |
| `15200` | Manufacture of footwear | `manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `16220` | Manufacture of builders' carpentry and joinery | `manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `17020` | Manufacture of paper and paperboard containers | `manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `18120` | Printing services | `printing_publication` | percentage | `item 64 catch-all` | by office area |  |
| `20230` | Manufacture of soap, detergents and cleaning preparations | `manufacturer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `22200` | Manufacture of plastic products | `manufacturer` | graduated | `manufacturer_small_scale` | ₱3,300 |  |
| `23950` | Manufacture of concrete products (hollow blocks) | `manufacturer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `25920` | Treatment and coating of metals (machine shop) | `contractor` | graduated | `lathe_machine_shop` | ₱1,100 |  |
| `31001` | Manufacture of furniture | `manufacturer` | graduated | `item 64 catch-all` | by office area |  |
| `32110` | Manufacture of jewellery and related articles | `manufacturer` | graduated | `goldsmith` | ₱1,100 |  |
| `36000` | Water collection, treatment and supply (water refilling) | `manufacturer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `38110` | Collection of non-hazardous waste | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `41000` | Construction of buildings (general contractor) | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `43210` | Electrical installation | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `43220` | Plumbing, heating and air-conditioning installation | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `43300` | Building completion and finishing | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `45201` | Maintenance and repair of motor vehicles (auto repair) | `contractor` | graduated | `motor_repair_similar_shop` | ₱1,100 |  |
| `45301` | Sale of motor vehicle parts and accessories | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `45401` | Sale, maintenance and repair of motorcycles | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `46100` | Wholesale on a fee or contract basis (commission agent) | `wholesaler` | graduated | `item 64 catch-all` | by office area |  |
| `46301` | Wholesale of rice, corn and other grains | `essential_wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46302` | Wholesale of fruits and vegetables | `essential_wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46303` | Wholesale of meat, poultry and seafood | `essential_wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46309` | Wholesale of other food, beverages and tobacco | `wholesaler` | graduated | `wholesaler` | ₱5,000 | asks essentials |
| `46410` | Wholesale of textiles, clothing and footwear | `wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46491` | Wholesale of household appliances and furniture | `wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46520` | Wholesale of electronic and telecommunications equipment | `wholesaler` | graduated | `wholesaler` | ₱5,000 |  |
| `46630` | Wholesale of construction materials and hardware | `wholesaler` | graduated | `wholesaler` | ₱5,000 | asks essentials |
| `46691` | Wholesale of chemical and pharmaceutical products | `wholesaler` | graduated | `wholesaler` | ₱5,000 | asks essentials |
| `46900` | Non-specialized wholesale trade | `wholesaler` | graduated | `wholesaler` | ₱5,000 | asks essentials |
| `47111` | Retail sale in non-specialized stores (sari-sari store) | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47112` | Retail sale in non-specialized stores (grocery or mini-mart) | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47190` | Other retail sale in non-specialized stores (department store) | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47211` | Retail sale of rice, corn and other grains | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47212` | Retail sale of fruits and vegetables | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47213` | Retail sale of meat and meat products | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47214` | Retail sale of fish and other seafood | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47219` | Retail sale of other food products (dry goods) | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47220` | Retail sale of beverages | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47230` | Retail sale of tobacco products | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47300` | Retail sale of automotive fuel (gasoline station) | `retailer` | graduated | `gas_station` | graduated |  |
| `47411` | Retail sale of computers and peripheral equipment | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47412` | Retail sale of telecommunications equipment (cellphone shop) | `retailer` | graduated | `cellphone_dealer` | ₱1,100 |  |
| `47420` | Retail sale of audio and video equipment | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47510` | Retail sale of textiles | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47521` | Retail sale of hardware and building materials | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47522` | Retail sale of paints, glass and plumbing supplies | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47591` | Retail sale of furniture | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47592` | Retail sale of household appliances | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47610` | Retail sale of books, newspapers and stationery | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47640` | Retail sale of sporting goods | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47650` | Retail sale of games and toys | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47711` | Retail sale of clothing and apparel | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47712` | Retail sale of footwear and leather goods | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47721` | Retail sale of pharmaceutical goods (pharmacy) | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47722` | Retail sale of medical and orthopaedic goods | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47723` | Retail sale of cosmetics and toilet articles | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47730` | Retail sale of jewellery and watches | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47733` | Retail sale of agricultural supplies, feeds and fertilizers | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47741` | Retail sale of second-hand goods (ukay-ukay) | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47760` | Retail sale of flowers, plants, pets and pet food | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47810` | Retail sale of food products via stalls and markets | `essential_retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47820` | Retail sale of textiles and footwear via stalls and markets | `retailer` | graduated | `item 64 catch-all` | by office area |  |
| `47912` | Retail sale via internet (online store) | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `47990` | Other retail sale not in stores (direct selling) | `retailer` | graduated | `item 64 catch-all` | by office area | asks essentials |
| `49221` | Passenger land transport (jeepney, UV express, tricycle) | `puv_operator` | per unit | `item 64 catch-all` | by office area |  |
| `49230` | Freight transport by road (trucking) | `puv_operator` | per unit | `item 64 catch-all` | by office area |  |
| `52101` | Warehousing and storage | `contractor` | graduated | `warehouse_bodega` | graduated |  |
| `52290` | Other transportation support activities (freight forwarding) | `contractor` | graduated | `logistic_service` | ₱3,300 |  |
| `53100` | Postal and courier activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `55101` | Hotels and resorts | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `55102` | Apartelles, pension houses and inns | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `55103` | Motels and lodging houses | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `55900` | Other accommodation (dormitory and boarding house) | `lessor` | graduated | `boarding_house` | ₱550 |  |
| `56101` | Restaurants and carinderia | `restaurant` | graduated | `item 64 catch-all` | by office area |  |
| `56102` | Fast-food and quick-service restaurants | `restaurant` | graduated | `item 64 catch-all` | by office area |  |
| `56103` | Refreshment stands, kiosks and food carts | `restaurant` | graduated | `item 64 catch-all` | by office area |  |
| `56210` | Event catering services | `restaurant` | graduated | `independent_caterer` | ₱550 |  |
| `56290` | Other food service activities (canteen and institutional catering) | `restaurant` | graduated | `item 64 catch-all` | by office area |  |
| `56301` | Beverage serving activities (coffee shop) | `restaurant` | graduated | `cafe_cafeteria` | ₱880 |  |
| `56302` | Bars, beer houses and drinking places | `bar_nightclub` | graduated | `item 64 catch-all` | by office area |  |
| `58130` | Publishing of newspapers and periodicals | `printing_publication` | percentage | `item 64 catch-all` | by office area |  |
| `59140` | Motion picture projection (cinema) | `amusement_place` | graduated | `movie_house` | ₱4,400 |  |
| `61100` | Wired telecommunications activities | `franchise_holder` | percentage | `item 64 catch-all` | by office area |  |
| `62010` | Computer programming activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `62090` | Other information technology and computer service activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `63110` | Data processing, hosting and related activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `64920` | Other credit granting (lending investor and pawnshop) | `bank` | percentage | `financial_institution` | ₱4,400 |  |
| `64990` | Other financial service activities (money remittance) | `bank` | percentage | `financial_institution` | ₱4,400 |  |
| `65120` | Non-life insurance | `bank` | percentage | `financial_institution` | ₱4,400 |  |
| `68100` | Lessor of real estate (apartments, stalls, commercial space) | `lessor` | graduated | `item 64 catch-all` | by office area |  |
| `68200` | Real estate activities on a fee or contract basis (brokerage) | `real_estate_dealer` | graduated | `real_estate_dealer` | ₱4,400 |  |
| `69100` | Legal activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `69200` | Accounting, bookkeeping and auditing activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `70200` | Management consultancy activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `71100` | Architectural and engineering activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `73100` | Advertising | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `74200` | Photographic activities (photo studio) | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `75000` | Veterinary activities | `contractor` | graduated | `veterinary_clinic` | ₱1,100 |  |
| `77100` | Renting and leasing of motor vehicles (rent-a-car) | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `77290` | Renting and leasing of other personal and household goods | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `78100` | Activities of employment placement agencies | `contractor` | graduated | `recruitment_service` | ₱4,400 |  |
| `79110` | Travel agency activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `80100` | Private security activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `81210` | General cleaning of buildings (janitorial services) | `contractor` | graduated | `janitorial_manpower_service` | ₱4,400 |  |
| `82200` | Activities of call centres | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `82990` | Other business support service activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `85100` | Pre-primary and primary education (private school) | `contractor` | graduated | `learning_institute` | ₱6,600 |  |
| `85490` | Other education (review, tutorial and driving schools) | `contractor` | graduated | `learning_institute` | ₱6,600 |  |
| `86100` | Hospital activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `86201` | Medical and dental clinic activities | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `86901` | Medical and diagnostic laboratory activities | `contractor` | graduated | `medical_dental_lab` | ₱1,100 |  |
| `92000` | Gambling and betting activities (lotto outlet) | `amusement_place` | graduated | `item 64 catch-all` | by office area |  |
| `93110` | Operation of sports and fitness facilities (gym) | `amusement_place` | graduated | `sports_recreational_facility` | ₱3,300 |  |
| `93290` | Other amusement and recreation (billiard hall, videoke, internet cafe) | `amusement_place` | graduated | `item 64 catch-all` | by office area |  |
| `95110` | Repair of computers and peripheral equipment | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `95210` | Repair of consumer electronics | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `95220` | Repair of household appliances | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `95230` | Repair of footwear and leather goods | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `95290` | Repair of other personal and household goods | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `96110` | Barbershop and hairdressing | `contractor` | graduated | `barber_shop` | ₱550 |  |
| `96120` | Beauty parlour, salon and spa services | `contractor` | graduated | `item 64 catch-all` | by office area |  |
| `96200` | Laundry and dry-cleaning services | `contractor` | graduated | `motor_repair_similar_shop` | ₱1,100 |  |
| `96301` | Funeral and related activities | `contractor` | graduated | `funeral_independent` | ₱5,500 |  |
| `96990` | Other personal service activities | `contractor` | graduated | `other_independent_contractor` | ₱550 |  |
