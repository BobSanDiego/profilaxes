# Teachers.Net Core Terms Short Label Audit

Status: audit only
Ticket: CTJ005
Framework: `teachers-net`
Source: active compiled Core Terms taxonomy after CTJ004

## 1. Executive Summary

The active Teachers.Net Core Terms taxonomy is already in good short-label shape for Grade Level and Subject Area. The highest-confidence short labels are mostly already present: `Math`, `ELA`, `ESL/ELL`, `PE/Health`, `CTE`, `TK`, `Adult Ed`, and `Reading/Lit`.

The strongest immediate improvement is to standardize a small set of display labels that are familiar to educators and useful in dense UI contexts: `Grades`, `Subject`, `Higher Ed`, and `Special Ed`. A broader set of compact labels, especially `Elem`, `MS`, `HS`, state postal abbreviations, `CS`, `Tech`, and `World Lang`, should go through human review before being applied because they are useful in dense tables but may be too terse for public filters or detail pages.

No taxonomy changes were made in this ticket. Future implementation should let Core Terms provide context-aware display representations so Jobs and other consumer plugins do not make terminology decisions locally.

## 2. Statistics

- Total active terms: 101
- Terms reviewed: 101
- Current short labels that already differ from canonical label: 11
- Missing short labels: 0
- Recommended changes from current short label: 63
- High-confidence apply-now changes: 4
- Human-review recommendations: 59
- Leave unchanged recommendations: 38
- Average characters saved across positive recommended changes: 6.8
- Largest character savings:
  - District of Columbia -> DC: 18 characters
  - Computer Science -> CS: 14 characters
  - North Carolina -> NC: 12 characters
  - South Carolina -> SC: 12 characters
  - Middle School -> MS: 11 characters
  - Massachusetts -> MA: 11 characters
  - New Hampshire -> NH: 11 characters
  - West Virginia -> WV: 11 characters

## 3. Recommendation Table

| Axis | Canonical label | Current short label | Recommended short label | Characters saved | Preferred display context | Confidence | Apply now | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Grade Level | Grade Level | Grade Level | Grades | 5 | Dense Table, Filter Panel | High | Yes | Shortens the axis label without changing meaning. |
| Grade Level | Adult Education | Adult Ed | Adult Ed | 7 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Grade Level | Early Childhood | Early Childhood | Early Childhood | 0 | Detail Page, Filter Panel | High | No | The current label is readable and already compact enough for the concept. |
| Grade Level | Early Learners | Early Learners | Early Learners | 0 | Detail Page, Filter Panel | High | No | Avoids obscure abbreviation for a sensitive early-childhood audience. |
| Grade Level | Kindergarten | Kindergarten | Kindergarten | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Pre-K | Pre-K | Pre-K | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Transitional Kindergarten | TK | TK | 23 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Grade Level | Elementary | Elementary | Elem | 6 | Dense Table | Medium | Review | Common abbreviation, but full label is clearer in filters and public browse UI. |
| Grade Level | Grade 1 | Grade 1 | Grade 1 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 2 | Grade 2 | Grade 2 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 3 | Grade 3 | Grade 3 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 4 | Grade 4 | Grade 4 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 5 | Grade 5 | Grade 5 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | High School | High School | HS | 9 | Dense Table | Medium | Review | Common shorthand, but may be too terse outside dense tables. |
| Grade Level | Grade 10 | Grade 10 | Grade 10 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 11 | Grade 11 | Grade 11 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 12 | Grade 12 | Grade 12 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 9 | Grade 9 | Grade 9 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Higher Education | Higher Education | Higher Ed | 7 | Dense Table, Filter Panel | High | Yes | Common educator shorthand with strong readability. |
| Grade Level | Middle School | Middle School | MS | 11 | Dense Table | Medium | Review | Common shorthand, but may be too terse outside dense tables. |
| Grade Level | Grade 6 | Grade 6 | Grade 6 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 7 | Grade 7 | Grade 7 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 8 | Grade 8 | Grade 8 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Location | Location | Location | Location | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Location | International / Outside U.S. | International | International | 15 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Location | Remote | Remote | Remote | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Location | United States | United States | U.S. | 9 | Dense Table | High | Review | Standard abbreviation, but full label may be clearer in filters. |
| Location | Alabama | Alabama | AL | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Alaska | Alaska | AK | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Arizona | Arizona | AZ | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Arkansas | Arkansas | AR | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | California | California | CA | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Colorado | Colorado | CO | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Connecticut | Connecticut | CT | 9 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Delaware | Delaware | DE | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | District of Columbia | District of Columbia | DC | 18 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Florida | Florida | FL | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Georgia | Georgia | GA | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Hawaii | Hawaii | HI | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Idaho | Idaho | ID | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Illinois | Illinois | IL | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Indiana | Indiana | IN | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Iowa | Iowa | IA | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Kansas | Kansas | KS | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Kentucky | Kentucky | KY | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Louisiana | Louisiana | LA | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Maine | Maine | ME | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Maryland | Maryland | MD | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Massachusetts | Massachusetts | MA | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Michigan | Michigan | MI | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Minnesota | Minnesota | MN | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Mississippi | Mississippi | MS | 9 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Missouri | Missouri | MO | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Montana | Montana | MT | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Nebraska | Nebraska | NE | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Nevada | Nevada | NV | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Hampshire | New Hampshire | NH | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Jersey | New Jersey | NJ | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Mexico | New Mexico | NM | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New York | New York | NY | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | North Carolina | North Carolina | NC | 12 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | North Dakota | North Dakota | ND | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Ohio | Ohio | OH | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Oklahoma | Oklahoma | OK | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Oregon | Oregon | OR | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Pennsylvania | Pennsylvania | PA | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Rhode Island | Rhode Island | RI | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | South Carolina | South Carolina | SC | 12 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | South Dakota | South Dakota | SD | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Tennessee | Tennessee | TN | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Texas | Texas | TX | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Utah | Utah | UT | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Vermont | Vermont | VT | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Virginia | Virginia | VA | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Washington | Washington | WA | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | West Virginia | West Virginia | WV | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Wisconsin | Wisconsin | WI | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Wyoming | Wyoming | WY | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Subject Area | Subject Area | Subject Area | Subject | 5 | Dense Table, Filter Panel | High | Yes | Shortens the axis label while preserving meaning in Jobs contexts. |
| Subject Area | Art | Art | Art | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Crafts and such | Crafts and such | Crafts and such | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Career Technical Education | CTE | CTE | 23 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Counseling | Counseling | Counseling | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | English Language Arts | ELA | ELA | 18 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | English Learners / ESL | ESL/ELL | ESL/ELL | 15 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | General / Multiple Subjects | Gen/Mult Subj | Gen/Mult Subj | 14 | Dense Table | Medium | No | Already uses the recommended short label; no change needed. |
| Subject Area | Library / Media | Library/Media | Library/Media | 2 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Mathematics | Math | Math | 7 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Algebra | Algebra | Algebra | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Music | Music | Music | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Physical Education / Health | PE/Health | PE/Health | 18 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Reading / Literacy | Reading/Lit | Reading/Lit | 7 | Dense Table | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Science | Science | Science | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Biology | Biology | Biology | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Social Studies | Social Studies | Soc Studies | 3 | Dense Table | Medium | Review | Saves little and is less polished than the full label. |
| Subject Area | History | History | History | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Special Education | Special Education | Special Ed | 7 | Dense Table, Filter Panel | High | Yes | Common educator shorthand with strong recognition. |
| Subject Area | Technology | Technology | Tech | 6 | Dense Table | Medium | Review | Recognizable, but full label is clearer in filters. |
| Subject Area | Computer Science | Computer Science | CS | 14 | Dense Table | Medium | Review | Common in some contexts but can be ambiguous outside course catalogs. |
| Subject Area | World Languages | World Languages | World Lang | 5 | Dense Table | Medium | Review | Readable but less formal than the canonical label. |
| Subject Area | Spanish | Spanish | Spanish | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |

## 4. Human Review Section

These terms may be useful in dense interfaces, but they should receive product review before becoming default short labels because the abbreviation may be too terse, context-dependent, or less polished than the canonical label.

| Axis | Canonical label | Current short label | Recommended short label | Characters saved | Preferred display context | Confidence | Apply now | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Grade Level | Elementary | Elementary | Elem | 6 | Dense Table | Medium | Review | Common abbreviation, but full label is clearer in filters and public browse UI. |
| Grade Level | High School | High School | HS | 9 | Dense Table | Medium | Review | Common shorthand, but may be too terse outside dense tables. |
| Grade Level | Middle School | Middle School | MS | 11 | Dense Table | Medium | Review | Common shorthand, but may be too terse outside dense tables. |
| Location | United States | United States | U.S. | 9 | Dense Table | High | Review | Standard abbreviation, but full label may be clearer in filters. |
| Location | Alabama | Alabama | AL | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Alaska | Alaska | AK | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Arizona | Arizona | AZ | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Arkansas | Arkansas | AR | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | California | California | CA | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Colorado | Colorado | CO | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Connecticut | Connecticut | CT | 9 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Delaware | Delaware | DE | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | District of Columbia | District of Columbia | DC | 18 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Florida | Florida | FL | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Georgia | Georgia | GA | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Hawaii | Hawaii | HI | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Idaho | Idaho | ID | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Illinois | Illinois | IL | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Indiana | Indiana | IN | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Iowa | Iowa | IA | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Kansas | Kansas | KS | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Kentucky | Kentucky | KY | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Louisiana | Louisiana | LA | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Maine | Maine | ME | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Maryland | Maryland | MD | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Massachusetts | Massachusetts | MA | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Michigan | Michigan | MI | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Minnesota | Minnesota | MN | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Mississippi | Mississippi | MS | 9 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Missouri | Missouri | MO | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Montana | Montana | MT | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Nebraska | Nebraska | NE | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Nevada | Nevada | NV | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Hampshire | New Hampshire | NH | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Jersey | New Jersey | NJ | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New Mexico | New Mexico | NM | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | New York | New York | NY | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | North Carolina | North Carolina | NC | 12 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | North Dakota | North Dakota | ND | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Ohio | Ohio | OH | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Oklahoma | Oklahoma | OK | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Oregon | Oregon | OR | 4 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Pennsylvania | Pennsylvania | PA | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Rhode Island | Rhode Island | RI | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | South Carolina | South Carolina | SC | 12 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | South Dakota | South Dakota | SD | 10 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Tennessee | Tennessee | TN | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Texas | Texas | TX | 3 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Utah | Utah | UT | 2 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Vermont | Vermont | VT | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Virginia | Virginia | VA | 6 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Washington | Washington | WA | 8 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | West Virginia | West Virginia | WV | 11 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Wisconsin | Wisconsin | WI | 7 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Location | Wyoming | Wyoming | WY | 5 | Dense Table | High | Review | Postal abbreviation is familiar in compact location displays, but full state names remain better for broad filters. |
| Subject Area | Social Studies | Social Studies | Soc Studies | 3 | Dense Table | Medium | Review | Saves little and is less polished than the full label. |
| Subject Area | Technology | Technology | Tech | 6 | Dense Table | Medium | Review | Recognizable, but full label is clearer in filters. |
| Subject Area | Computer Science | Computer Science | CS | 14 | Dense Table | Medium | Review | Common in some contexts but can be ambiguous outside course catalogs. |
| Subject Area | World Languages | World Languages | World Lang | 5 | Dense Table | Medium | Review | Readable but less formal than the canonical label. |

## 5. Leave Unchanged Section

These terms are already appropriately concise or should remain explicit for readability.

| Axis | Canonical label | Current short label | Recommended short label | Characters saved | Preferred display context | Confidence | Apply now | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Grade Level | Adult Education | Adult Ed | Adult Ed | 7 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Grade Level | Early Childhood | Early Childhood | Early Childhood | 0 | Detail Page, Filter Panel | High | No | The current label is readable and already compact enough for the concept. |
| Grade Level | Early Learners | Early Learners | Early Learners | 0 | Detail Page, Filter Panel | High | No | Avoids obscure abbreviation for a sensitive early-childhood audience. |
| Grade Level | Kindergarten | Kindergarten | Kindergarten | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Pre-K | Pre-K | Pre-K | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Transitional Kindergarten | TK | TK | 23 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Grade Level | Grade 1 | Grade 1 | Grade 1 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 2 | Grade 2 | Grade 2 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 3 | Grade 3 | Grade 3 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 4 | Grade 4 | Grade 4 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 5 | Grade 5 | Grade 5 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 10 | Grade 10 | Grade 10 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 11 | Grade 11 | Grade 11 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 12 | Grade 12 | Grade 12 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 9 | Grade 9 | Grade 9 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 6 | Grade 6 | Grade 6 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 7 | Grade 7 | Grade 7 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Grade Level | Grade 8 | Grade 8 | Grade 8 | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Location | Location | Location | Location | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Location | International / Outside U.S. | International | International | 15 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Location | Remote | Remote | Remote | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Art | Art | Art | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Crafts and such | Crafts and such | Crafts and such | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Career Technical Education | CTE | CTE | 23 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Counseling | Counseling | Counseling | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | English Language Arts | ELA | ELA | 18 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | English Learners / ESL | ESL/ELL | ESL/ELL | 15 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | General / Multiple Subjects | Gen/Mult Subj | Gen/Mult Subj | 14 | Dense Table | Medium | No | Already uses the recommended short label; no change needed. |
| Subject Area | Library / Media | Library/Media | Library/Media | 2 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Mathematics | Math | Math | 7 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Algebra | Algebra | Algebra | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Music | Music | Music | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Physical Education / Health | PE/Health | PE/Health | 18 | Dense Table, Filter Panel | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Reading / Literacy | Reading/Lit | Reading/Lit | 7 | Dense Table | High | No | Already uses the recommended short label; no change needed. |
| Subject Area | Science | Science | Science | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Biology | Biology | Biology | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | History | History | History | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |
| Subject Area | Spanish | Spanish | Spanish | 0 | Detail Page, Filter Panel | High | No | Already concise and readable; shortening would reduce clarity. |

## 6. Future Considerations

### Context-Aware Display Labels

Core Terms should eventually support display representations by context instead of relying on one short label for every surface. A dense table may need `HS`, while a public filter may be clearer as `High School`, and a detail page may prefer the full canonical label. Consumer plugins should request the appropriate representation from Core Terms rather than maintaining their own abbreviation maps.

### Alias And Synonym Support

Aliases and synonyms remain separate from display labels. A future alias system should help map inputs such as imported seed data or external feeds to canonical Core Terms. It should not be used as a substitute for carefully chosen public display labels.

### Display Resolver API

A future display resolver could expose canonical, short, dense, filter, and detail representations through a stable Core Terms API. That would let Jobs, Chatboards, Lesson Bank, and future modules stay aligned without hardcoding terminology. This ticket does not implement that API.
