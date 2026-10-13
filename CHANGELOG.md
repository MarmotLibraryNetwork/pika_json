# Pika JSON — Release Notes

## v1.2.0 (2026-10-13)

**What's new**

- **Reverse links between people.** A person's record (`/pika-json/taxonomy/{tid}`) now includes `field_related_person_reverse`, listing other people, events, and organizations whose "related person" entries point at this person, even when this person's record doesn't link back. Each entry identifies the linking record (`host`) and indicates whether this person already links to it (`linked_forward`). Links from repository items are not included yet.

---

## v1.1.0 (2026-05-12)

**What's new**

- **Faster, paginated child listings.** Collections and Compound Objects with many child items (e.g., a large photo collection or multi-page document) can now have their children fetched in pages instead of all at once. A new endpoint, `/pika-json/node/{nid}/children`, returns children 10 at a time by default, with page and page-size controls. This avoids slow, oversized responses for large collections.
- **More complete thumbnail information for subjects/topics.** Taxonomy term thumbnails now include additional details (alt text, title, image dimensions) when available, matching the level of detail already provided for other images.
- **Fixed a data accuracy bug.** Fields that can hold multiple values (for example, a record with several notes or several identifiers) were previously only returning the first value. All values are now returned correctly.

**Please note — a change in behavior**

- Child items are no longer automatically included inside the main record response for Collections and Compound Objects. Any integration that previously read `children` from the `/pika-json/node/{nid}` response needs to switch to the new `/pika-json/node/{nid}/children` endpoint described above.

---

## v1.0.0 (2026-04-24)

Initial release. Provides JSON endpoints for:

- A single repository item and its associated media (`/pika-json/node/{nid}`), including embedded child items for Collections and Compound Objects.
- A single taxonomy term/subject heading (`/pika-json/taxonomy/{tid}`).
