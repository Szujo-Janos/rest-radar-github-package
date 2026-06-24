# REST Radar – Endpoint Inspector

A **REST Radar** egy WordPress REST API ellenőrző, QA riportoló, endpoint-védelmi, regressziós összehasonlító és finding triage plugin.

Segít átnézni, hogy egy WordPress oldalon milyen REST API endpointok vannak regisztrálva, ezek közül melyek lehetnek kockázatosak, miért azok, hogyan változtak frissítés után, és milyen review döntés született róluk.

## Mire jó?

- REST API endpointok listázása
- jogosultsági problémák felismerése
- hiányzó vagy gyenge `permission_callback` jelzése
- kockázati szintek megjelenítése
- risk explanation / magyarázat
- QA hibajegy-vázlat generálása
- fejlesztői javítási kódrészlet javaslata
- biztonságos GET probe
- CSV és Markdown riport export
- Endpoint Shield szabályok létrehozása
- snapshot mentés és frissítés előtti/utáni összehasonlítás
- review státusz, jegyzet és severity override kezelése
- Retest required jelzés, ha egy korábban elfogadott endpoint megváltozik
- optimalizált dashboard priority queue / review progress / system state blokkokkal
- biztonságosabb Auto Safe Mode megerősítési logika
- opcionális IP anonimizálás Shield logoknál
- opcionális uninstall cleanup

## Review státuszok

- New
- Needs review
- Accepted public
- False positive
- Fix required
- Shielded
- Retest required

## Kinek hasznos?

- WordPress fejlesztőknek
- QA tesztelőknek
- weboldal-karbantartóknak
- ügynökségeknek
- API/security review munkához
- portfólió-projektként QA profilhoz

## Fontos

A plugin nem helyettesít teljes biztonsági auditot. Ellenőrző, dokumentáló és nem romboló védelmi segédeszköz. A találatokat mindig kézzel is ellenőrizni kell.

## Verzió

Aktuális csomagverzió: **0.9.1**
