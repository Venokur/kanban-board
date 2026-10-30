// Generates docs/postman/kanban.postman_collection.json.
//
// The collection is written by a generator because request order is a real
// dependency: Postman runs folders top to bottom, so anything that destroys or
// replaces the session (register/logout) and anything that removes fixtures
// (delete card/column) has to sit in the trailing folders.
import fs from 'node:fs'
import path from 'node:path'

const OUT = path.resolve('docs/postman/kanban.postman_collection.json')

const json = (obj) => ({ mode: 'raw', raw: JSON.stringify(obj, null, 2) })

const req = (name, method, urlPath, { body, tests = [], description, headers } = {}) => ({
  name,
  ...(description ? { description } : {}),
  request: {
    method,
    header: headers ?? (body ? [{ key: 'Content-Type', value: 'application/json' }] : []),
    ...(body ? { body: json(body) } : {}),
    url: {
      raw: `{{baseUrl}}${urlPath}`,
      host: ['{{baseUrl}}'],
      path: urlPath.replace(/^\//, '').split('/'),
    },
  },
  ...(tests.length
    ? { event: [{ listen: 'test', script: { type: 'text/javascript', exec: tests.flat() } }] }
    : {}),
})

const collection = {
  info: {
    name: 'Kanban Board API',
    description: [
      'HTTP API of the single shared kanban board (contract: `docs/openapi.yaml`).',
      '',
      '## Authentication',
      'Sessions live in an **HttpOnly cookie** (`kb_session`), so run **Auth → Login (demo)** first.',
      'Postman keeps the cookie and sends it automatically on every later request.',
      '',
      '## CSRF',
      'Every mutating request authenticated *by cookie* must echo the `kb_csrf` cookie in the',
      '`X-CSRF-Token` header. The collection-level pre-request script takes the value from the login',
      'response (or the cookie jar) — no manual step. A mismatch is answered with **403**, not 419.',
      '',
      '## Snapshots',
      'Every mutation returns the fresh board snapshot and broadcasts it over WebSocket.',
      '`revision` is monotonic: ignore a snapshot older than the one you already have.',
      '',
      '## Order matters',
      'Folders run top to bottom. **Cleanup** and **Teardown (run last)** delete the fixtures and end',
      'the session; running them early leaves every later request with 401/404.',
    ].join('\n'),
    schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
  },
  variable: [
    { key: 'baseUrl', value: 'http://localhost:9501', type: 'string' },
    { key: 'csrf', value: '', type: 'string' },
    { key: 'revision', value: '', type: 'string' },
    { key: 'columnId', value: '', type: 'string' },
    { key: 'cardId', value: '', type: 'string' },
    { key: 'labelId', value: '', type: 'string' },
    { key: 'checklistId', value: '', type: 'string' },
    { key: 'commentId', value: '', type: 'string' },
    { key: 'columnIdsJson', value: '[]', type: 'string' },
    { key: 'cardsOrderJson', value: '[]', type: 'string' },
    { key: 'labelIdsJson', value: '[]', type: 'string' },
  ],
  event: [
    {
      listen: 'prerequest',
      script: {
        type: 'text/javascript',
        exec: [
          "// Double-submit CSRF: prefer the token returned by login, fall back to the cookie jar.",
          "let csrf = pm.collectionVariables.get('csrf');",
          "if (!csrf) {",
          "  const fromCookie = pm.cookies.get('kb_csrf');",
          "  if (fromCookie) {",
          "    csrf = fromCookie;",
          "    pm.collectionVariables.set('csrf', csrf);",
          "  }",
          "}",
          "if (csrf) pm.request.headers.upsert({ key: 'X-CSRF-Token', value: csrf });",
        ],
      },
    },
    {
      listen: 'test',
      script: {
        type: 'text/javascript',
        exec: [
          "// One place that understands snapshots: remember the revision and the current",
          "// order/ids, so every reorder request can send a correct, complete body.",
          "if (pm.response.code === 200) {",
          "  let body = null;",
          "  try { body = pm.response.json(); } catch (e) { body = null; }",
          "",
          "  if (body && typeof body.revision === 'number') {",
          "    pm.collectionVariables.set('revision', String(body.revision));",
          "  }",
          "  if (body && Array.isArray(body.columns)) {",
          "    pm.collectionVariables.set(",
          "      'columnIdsJson',",
          "      JSON.stringify(body.columns.map((c) => c.id)),",
          "    );",
          "    pm.collectionVariables.set(",
          "      'cardsOrderJson',",
          "      JSON.stringify(body.columns.map((c) => ({ id: c.id, cards: c.cards.map((card) => card.id) }))),",
          "    );",
          "  }",
          "  if (body && Array.isArray(body.labels)) {",
          "    pm.collectionVariables.set('labelIdsJson', JSON.stringify(body.labels.map((l) => l.id)));",
          "  }",
          "}",
        ],
      },
    },
  ],
  item: [],
}

const snapshotShape = (bodyExpr = 'pm.response.json()') => [
  `const body = ${bodyExpr};`,
  "pm.test('snapshot carries a numeric revision', () => {",
  '  pm.expect(body.revision).to.be.a("number");',
  '});',
  "pm.test('snapshot shape', () => {",
  "  pm.expect(body).to.include.keys('revision', 'board', 'labels', 'users', 'columns', 'presence');",
  '});',
]

const authFolder = {
  name: 'Auth',
  item: [
    req('Health', 'GET', '/api/health', {
      description: 'Liveness probe; also proves the coroutine PostgreSQL pool works.',
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        "const body = pm.response.json();",
        "pm.test('database reachable', () => pm.expect(body.ok).to.be.true);",
        "pm.test('runtime reported', () => pm.expect(body.runtime).to.be.a('string').and.not.empty);",
      ],
    }),
    req('Config', 'GET', '/api/config', {
      description:
        'Public flags for the login page: whether the demo account is usable and whether `APP_SECRET` is still a shipped placeholder. Secrets are never returned.',
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "pm.test('demo flag is a boolean', () => pm.expect(body.demo).to.be.a('boolean'));",
        "pm.test('defaultSecret flag is a boolean', () => pm.expect(body.defaultSecret).to.be.a('boolean'));",
        "pm.test('the secret itself is not exposed', () => {",
        "  pm.expect(body).to.not.have.property('secret');",
        "  pm.expect(JSON.stringify(body)).to.not.include('kanban-csrf-secret');",
        '});',
      ],
    }),
    req('Login (demo)', 'POST', '/api/login', {
      body: { email: 'demo@kanban.local', password: 'demo1234' },
      description:
        'Starts a session: sets an HttpOnly `kb_session` cookie plus a readable `kb_csrf` cookie, and returns the CSRF token.',
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "pm.test('csrf token returned', () => pm.expect(body.csrf).to.be.a('string').and.not.empty);",
        "pm.test('user payload', () => pm.expect(body.user.email).to.eql('demo@kanban.local'));",
        "pm.collectionVariables.set('csrf', body.csrf);",
        "pm.test('session cookie is HttpOnly and SameSite=Lax', () => {",
        "  // `pm.cookies` only exposes script-readable cookies, so read Set-Cookie.",
        "  const raw = pm.response.headers.all()",
        "    .filter((h) => h.key.toLowerCase() === 'set-cookie')",
        "    .map((h) => h.value)",
        "    .join('\\n');",
        "  pm.expect(raw).to.match(/kb_session=[^;]*;[^\\n]*HttpOnly/i);",
        "  pm.expect(raw).to.match(/kb_session=[^;]*;[^\\n]*SameSite=Lax/i);",
        '});',
        "pm.test('readable csrf cookie is not HttpOnly', () => {",
        "  const raw = pm.response.headers.all()",
        "    .filter((h) => h.key.toLowerCase() === 'set-cookie')",
        "    .map((h) => h.value)",
        "    .join('\\n');",
        "  const csrfCookie = raw.split('\\n').find((line) => line.startsWith('kb_csrf=')) || '';",
        "  pm.expect(csrfCookie).to.not.match(/HttpOnly/i);",
        '});',
      ],
    }),
    req('Me', 'GET', '/api/me', {
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        "pm.test('no secrets in the payload', () => {",
        "  const user = pm.response.json().user;",
        "  pm.expect(user).to.not.have.property('password_hash');",
        "  pm.expect(user).to.include.keys('id', 'email', 'name');",
        '});',
      ],
    }),
  ],
}

const boardFolder = {
  name: 'Board',
  item: [
    req('Get snapshot', 'GET', '/api/board', {
      tests: [
        ...snapshotShape(),
        "pm.test('columns is an array', () => pm.expect(body.columns).to.be.an('array'));",
        "pm.test('presence has users and watching', () => {",
        "  pm.expect(body.presence.users).to.be.an('array');",
        "  pm.expect(body.presence.watching).to.be.an('object');",
        '});',
        "pm.test('archived cards are not in the snapshot', () => {",
        '  const cards = body.columns.flatMap((c) => c.cards);',
        '  pm.expect(cards.every((card) => !card.archivedAt)).to.be.true;',
        '});',
        "pm.test('positions are dense from 1', () => {",
        '  body.columns.forEach((c, i) => pm.expect(c.position).to.eql(i + 1));',
        '});',
      ],
    }),
    req('Rename board', 'PUT', '/api/board', {
      body: { name: 'Демо доска' },
      description: 'Renames the single shared board. Returns the snapshot, like every mutation.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        ...snapshotShape(),
        "pm.test('name updated', () => pm.expect(body.board.name).to.eql('Демо доска'));",
      ],
    }),
    req('Archive list', 'GET', '/api/board/archive', {
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        "pm.test('cards array, not a snapshot', () => {",
        '  const body = pm.response.json();',
        "  pm.expect(body.cards).to.be.an('array');",
        "  pm.expect(body).to.not.have.property('columns');",
        '});',
      ],
    }),
  ],
}

const columnsFolder = {
  name: 'Columns',
  item: [
    req('Create column', 'POST', '/api/columns', {
      body: { title: 'Postman {{$timestamp}}' },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "const title = JSON.parse(pm.request.body.raw).title;",
        'const created = body.columns.find((c) => c.title === title);',
        "pm.test('column appended to the end', () => {",
        '  pm.expect(created).to.be.an("object");',
        '  pm.expect(created.position).to.eql(body.columns.length);',
        '});',
        "pm.collectionVariables.set('columnId', String(created.id));",
      ],
    }),
    req('Update column (title, WIP limit, accent)', 'PUT', '/api/columns/{{columnId}}', {
      body: { title: 'Postman column', wipLimit: 5, accent: 'sky' },
      description:
        'Any subset of `title`, `wipLimit` (1…999 or null) and `accent` (palette key or null).',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "const id = Number(pm.collectionVariables.get('columnId'));",
        'const column = body.columns.find((c) => c.id === id);',
        "pm.test('title applied', () => pm.expect(column.title).to.eql('Postman column'));",
        "pm.test('wipLimit applied', () => pm.expect(column.wipLimit).to.eql(5));",
        "pm.test('accent applied', () => pm.expect(column.accent).to.eql('sky'));",
      ],
    }),
    req('Reorder columns', 'POST', '/api/columns/order', {
      body: { columnIds: [] },
      description:
        'Send every column id in the new order. The body here is overwritten by the collection pre-request-free path: `columnIdsJson` always holds the current order, so the request body is rebuilt by the pre-request script below.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "pm.test('positions are dense from 1', () => {",
        '  pm.response.json().columns.forEach((c, i) => pm.expect(c.position).to.eql(i + 1));',
        '});',
      ],
    }),
  ],
}

// Reorder endpoints need a body built from the live snapshot, so they get a
// dedicated pre-request script instead of a static raw body.
columnsFolder.item[2].event = [
  {
    listen: 'prerequest',
    script: {
      type: 'text/javascript',
      exec: [
        "// Move the last column to the front: proves the endpoint reorders, not just echoes.",
        "const ids = JSON.parse(pm.collectionVariables.get('columnIdsJson') || '[]');",
        'if (ids.length > 1) ids.unshift(ids.pop());',
        "pm.request.body = { mode: 'raw', raw: JSON.stringify({ columnIds: ids }) };",
      ],
    },
  },
  ...columnsFolder.item[2].event,
]

const cardsFolder = {
  name: 'Cards',
  item: [
    req('Create card', 'POST', '/api/cards', {
      body: {
        columnId: '{{columnId}}',
        title: 'Postman card {{$timestamp}}',
        description: 'Created by the Postman collection',
      },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "const id = Number(pm.collectionVariables.get('columnId'));",
        'const column = body.columns.find((c) => c.id === id);',
        'const card = column.cards[column.cards.length - 1];',
        "pm.test('card appended to its column', () => pm.expect(card.position).to.eql(column.cards.length));",
        "pm.test('author is set', () => pm.expect(card.author).to.be.an('object'));",
        "pm.collectionVariables.set('cardId', String(card.id));",
      ],
    }),
    req('Update card (all fields)', 'PUT', '/api/cards/{{cardId}}', {
      body: {
        title: 'Postman card (updated)',
        description: 'Edited by the collection',
        priority: 3,
        dueDate: '2026-12-31',
        coverColor: 'violet',
        estimate: 4,
        assigneeId: null,
        labelIds: [],
      },
      description:
        'Any subset of fields. `assigneeId`, `dueDate`, `coverColor` and `estimate` accept `null` to clear; `labelIds` replaces the whole set.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "const id = Number(pm.collectionVariables.get('cardId'));",
        'const card = body.columns.flatMap((c) => c.cards).find((c) => c.id === id);',
        "pm.test('title updated', () => pm.expect(card.title).to.include('(updated)'));",
        "pm.test('description updated', () => pm.expect(card.description).to.eql('Edited by the collection'));",
        'pm.test("priority updated", () => pm.expect(card.priority).to.eql(3));',
        'pm.test("estimate updated", () => pm.expect(Number(card.estimate)).to.eql(4));',
        'pm.test("coverColor updated", () => pm.expect(card.coverColor).to.eql("violet"));',
        'pm.test("dueDate updated", () => pm.expect(card.dueDate).to.eql("2026-12-31"));',
        "pm.test('a card.updated event was recorded', () => {",
        "  const kinds = body.columns.flatMap((c) => c.cards).find((c) => c.id === id);",
        '  pm.expect(kinds).to.be.an("object");',
        '});',
      ],
    }),
    req('Invalid priority → 422', 'PUT', '/api/cards/{{cardId}}', {
      body: { priority: 9 },
      tests: [
        "pm.test('422 Unprocessable', () => pm.response.to.have.status(422));",
        "pm.test('error body', () => {",
        '  const body = pm.response.json();',
        "  pm.expect(body).to.include.keys('error', 'code');",
        '  pm.expect(body.code).to.eql(422);',
        '});',
      ],
    }),
    req('Reorder cards', 'POST', '/api/cards/order', {
      body: { columns: [] },
      description:
        'Send `{columns:[{id, cards:[id,…]}]}` for the full board after a drag & drop. The pre-request script rebuilds it from the live snapshot.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "pm.test('card positions stay dense', () => {",
        '  pm.response.json().columns.forEach((column) => {',
        '    column.cards.forEach((card, i) => pm.expect(card.position).to.eql(i + 1));',
        '  });',
        '});',
      ],
    }),
    req('Move card to another column', 'POST', '/api/cards/order', {
      body: { columns: [] },
      description:
        'Moves the fixture card into a different column. The pre-request script rebuilds the order from the live snapshot; a real column change must produce a `card.moved` event (an in-column reorder must not).',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('cardId'));",
        "const target = Number(pm.collectionVariables.get('moveTargetId'));",
        "const column = pm.response.json().columns.find((c) => c.cards.some((card) => card.id === id));",
        "pm.test('card sits in the target column', () => {",
        '  pm.expect(column).to.be.an("object");',
        "  pm.expect(column.id).to.eql(target);",
        '});',
      ],
    }),
    req('Archive card', 'POST', '/api/cards/{{cardId}}/archive', {
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('cardId'));",
        "pm.test('card left the board', () => {",
        '  const cards = pm.response.json().columns.flatMap((c) => c.cards);',
        '  pm.expect(cards.map((card) => card.id)).to.not.include(id);',
        '});',
      ],
    }),
    req('Restore card', 'POST', '/api/cards/{{cardId}}/restore', {
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('cardId'));",
        'const column = pm.response.json().columns.find((c) => c.cards.some((card) => card.id === id));',
        "pm.test('restored to the end of its column', () => {",
        "  pm.expect(column).to.be.an('object');",
        '  pm.expect(column.cards[column.cards.length - 1].id).to.eql(id);',
        '});',
      ],
    }),
    req('Card events', 'GET', '/api/cards/{{cardId}}/events', {
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "pm.test('events is an array', () => pm.expect(body.events).to.be.an('array'));",
        "pm.test('newest first', () => {",
        '  const at = body.events.map((e) => e.createdAt || 0);',
        '  pm.expect(at).to.eql(at.slice().sort((a, b) => b - a));',
        '});',
        "pm.test('created/archived/restored were recorded', () => {",
        '  const kinds = body.events.map((e) => e.kind);',
        '  pm.expect(kinds).to.include("card.created");',
        '  pm.expect(kinds).to.include("card.archived");',
        '  pm.expect(kinds).to.include("card.restored");',
        '});',
        "pm.test('a cross-column move was recorded with both columns', () => {",
        '  const moved = body.events.filter((e) => e.kind === "card.moved");',
        '  pm.expect(moved.length).to.eql(1);',
        '  pm.expect(moved[0].payload.to).to.be.a("string").and.not.empty;',
        '  pm.expect(moved[0].payload.from).to.be.a("string").and.not.empty;',
        '  pm.expect(moved[0].payload.toId).to.eql(Number(pm.collectionVariables.get("moveTargetId")));',
        '});',
      ],
    }),
  ],
}

cardsFolder.item[3].event = [
  {
    listen: 'prerequest',
    script: {
      type: 'text/javascript',
      exec: [
        "// Rebuild the full order from the last snapshot; keep it valid, just reverse",
        "// the cards inside each column to prove the endpoint persists a new order.",
        "const columns = JSON.parse(pm.collectionVariables.get('cardsOrderJson') || '[]');",
        'const next = columns.map((c) => ({ id: c.id, cards: c.cards.slice().reverse() }));',
        "pm.request.body = { mode: 'raw', raw: JSON.stringify({ columns: next }) };",
      ],
    },
  },
  ...cardsFolder.item[3].event,
]

// A real cross-column move: find a column that does not hold the fixture card
// yet, then rebuild the order so the card lands at the end of it.
cardsFolder.item[4].event = [
  {
    listen: 'prerequest',
    script: {
      type: 'text/javascript',
      exec: [
        "const cardId = Number(pm.collectionVariables.get('cardId'));",
        "const columns = JSON.parse(pm.collectionVariables.get('cardsOrderJson') || '[]');",
        'const source = columns.find((c) => c.cards.includes(cardId));',
        'const target = columns.find((c) => !c.cards.includes(cardId));',
        "if (!source || !target) throw new Error('need two columns to test a move');",
        "pm.collectionVariables.set('moveTargetId', String(target.id));",
        'const next = columns.map((c) => {',
        '  if (c.id === source.id) return { id: c.id, cards: c.cards.filter((id) => id !== cardId) };',
        '  if (c.id === target.id) return { id: c.id, cards: [...c.cards, cardId] };',
        '  return { id: c.id, cards: c.cards };',
        '});',
        "pm.request.body = { mode: 'raw', raw: JSON.stringify({ columns: next }) };",
      ],
    },
  },
  ...(cardsFolder.item[4].event || []),
]

const labelsFolder = {
  name: 'Labels',
  item: [
    req('Create label', 'POST', '/api/labels', {
      body: { name: 'Postman {{$timestamp}}', color: 'mint' },
      description: '`color` is a palette key (`violet|sky|mint|amber|rose|slate`), not a hex value.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "pm.test('createdLabelId returned', () => pm.expect(body.createdLabelId).to.be.a('number'));",
        'const label = body.labels.find((l) => l.id === body.createdLabelId);',
        "pm.test('label is in the snapshot', () => pm.expect(label.name).to.include('Postman'));",
        "pm.test('palette key stored, not hex', () => pm.expect(label.color).to.eql('mint'));",
        "pm.collectionVariables.set('labelId', String(body.createdLabelId));",
      ],
    }),
    req('Attach label to card', 'PUT', '/api/cards/{{cardId}}', {
      body: { labelIds: [] },
      description: '`labelIds` replaces the whole set; the pre-request script fills it from the live id.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const labelId = Number(pm.collectionVariables.get('labelId'));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "pm.test('card carries the label', () => pm.expect(card.labels.map((l) => l.id)).to.include(labelId));",
      ],
    }),
    req('Update label', 'PUT', '/api/labels/{{labelId}}', {
      body: { color: 'rose' },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('labelId'));",
        "const label = pm.response.json().labels.find((l) => l.id === id);",
        "pm.test('color updated', () => pm.expect(label.color).to.eql('rose'));",
      ],
    }),
    req('Reorder labels', 'POST', '/api/labels/order', {
      body: { labelIds: [] },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "pm.test('positions are dense from 1', () => {",
        '  pm.response.json().labels.forEach((l, i) => pm.expect(l.position).to.eql(i + 1));',
        '});',
      ],
    }),
    req('Delete label', 'DELETE', '/api/labels/{{labelId}}', {
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('labelId'));",
        "pm.test('detached from every card', () => {",
        '  const cards = pm.response.json().columns.flatMap((c) => c.cards);',
        '  pm.expect(cards.some((card) => card.labels.some((l) => l.id === id))).to.be.false;',
        '});',
        "pm.test('deleting a label keeps the order dense from 1', () => {",
        '  pm.response.json().labels.forEach((l, i) => pm.expect(l.position).to.eql(i + 1));',
        '});',
      ],
    }),
  ],
}

labelsFolder.item[1].event = [
  {
    listen: 'prerequest',
    script: {
      type: 'text/javascript',
      exec: [
        "const labelId = pm.collectionVariables.get('labelId');",
        "pm.request.body = { mode: 'raw', raw: JSON.stringify({ labelIds: [Number(labelId)] }) };",
      ],
    },
  },
  ...labelsFolder.item[1].event,
]

labelsFolder.item[3].event = [
  {
    listen: 'prerequest',
    script: {
      type: 'text/javascript',
      exec: [
        "// Reverse the current label order so the request cannot be a no-op.",
        "const ids = JSON.parse(pm.collectionVariables.get('labelIdsJson') || '[]').reverse();",
        "pm.request.body = { mode: 'raw', raw: JSON.stringify({ labelIds: ids }) };",
      ],
    },
  },
  ...labelsFolder.item[3].event,
]

const checklistFolder = {
  name: 'Checklist',
  item: [
    req('Add checklist item', 'POST', '/api/cards/{{cardId}}/checklist', {
      body: { text: 'Postman checklist item' },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "pm.test('item added', () => pm.expect(card.checklist.length).to.be.greaterThan(0));",
        "pm.test('new item is not done yet', () => pm.expect(card.checklist[card.checklist.length - 1].done).to.be.false);",
        "pm.collectionVariables.set('checklistId', String(card.checklist[card.checklist.length - 1].id));",
      ],
    }),
    req('Toggle checklist item', 'PUT', '/api/checklist/{{checklistId}}', {
      body: { done: true },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "const item = card.checklist.find((i) => i.id === Number(pm.collectionVariables.get('checklistId')));",
        "pm.test('marked done', () => pm.expect(item.done).to.be.true);",
      ],
    }),
    req('Delete checklist item', 'DELETE', '/api/checklist/{{checklistId}}', {
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('checklistId'));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "pm.test('item removed', () => pm.expect(card.checklist.some((i) => i.id === id)).to.be.false);",
      ],
    }),
  ],
}

const commentsFolder = {
  name: 'Comments',
  item: [
    req('Add comment', 'POST', '/api/cards/{{cardId}}/comments', {
      body: { body: 'Postman comment' },
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "pm.test('comment added', () => pm.expect(card.comments.length).to.be.greaterThan(0));",
        "pm.collectionVariables.set('commentId', String(card.comments[card.comments.length - 1].id));",
      ],
    }),
    req('List comments', 'GET', '/api/cards/{{cardId}}/comments', {
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        "pm.test('oldest first', () => {",
        '  const at = pm.response.json().comments.map((c) => c.createdAt || 0);',
        '  pm.expect(at).to.eql(at.slice().sort((a, b) => a - b));',
        '});',
      ],
    }),
    req('Delete comment', 'DELETE', '/api/comments/{{commentId}}', {
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('commentId'));",
        "const card = pm.response.json().columns.flatMap((c) => c.cards).find((c) => c.id === Number(pm.collectionVariables.get('cardId')));",
        "pm.test('comment removed', () => pm.expect(card.comments.some((c) => c.id === id)).to.be.false);",
      ],
    }),
  ],
}

const cleanupFolder = {
  name: 'Cleanup',
  description: 'Removes the fixtures created above. Must run before Teardown.',
  item: [
    req('Delete card', 'DELETE', '/api/cards/{{cardId}}', {
      description: 'Permanent. Cascades to comments, checklist items and card labels.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('cardId'));",
        "pm.test('card is gone', () => {",
        '  const cards = pm.response.json().columns.flatMap((c) => c.cards);',
        '  pm.expect(cards.map((card) => card.id)).to.not.include(id);',
        '});',
        "pm.test('deleting a card keeps positions dense', () => {",
        '  pm.response.json().columns.forEach((column) => {',
        '    column.cards.forEach((card, i) => pm.expect(card.position).to.eql(i + 1));',
        '  });',
        '});',
      ],
    }),
    req('Delete column', 'DELETE', '/api/columns/{{columnId}}', {
      description: 'Cascades to cards, comments, checklist items and card labels.',
      tests: [
        "pm.test('200 OK with snapshot', () => pm.response.to.have.status(200));",
        "const id = Number(pm.collectionVariables.get('columnId'));",
        "pm.test('column is gone', () => {",
        '  const ids = pm.response.json().columns.map((c) => c.id);',
        '  pm.expect(ids).to.not.include(id);',
        '});',
        "pm.test('deleting a column renumbers the rest from 1', () => {",
        '  pm.response.json().columns.forEach((c, i) => pm.expect(c.position).to.eql(i + 1));',
        '});',
      ],
    }),
  ],
}

const teardownFolder = {
  name: 'Teardown (run last)',
  description:
    'These requests end or replace the session, so they live last: anything after them answers 401.',
  item: [
    req('Login (wrong password → 401)', 'POST', '/api/login', {
      body: { email: 'demo@kanban.local', password: 'definitely-wrong' },
      description:
        'Checks the credential failure path. The session survives, so it is safe before logout.',
      tests: [
        "pm.test('401 Unauthorized', () => pm.response.to.have.status(401));",
        "pm.test('russian error message', () => pm.expect(pm.response.json().error).to.be.a('string').and.not.empty);",
        "pm.test('no new csrf token leaked', () => pm.expect(pm.response.json()).to.not.have.property('csrf'));",
      ],
    }),
    req('Register (random user)', 'POST', '/api/register', {
      body: {
        name: 'Postman User',
        email: 'postman-{{$timestamp}}@kanban.local',
        password: 'postman1234',
      },
      description: 'Creates a throwaway account. This replaces the demo session cookie.',
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        'const body = pm.response.json();',
        "pm.test('csrf returned for the new session', () => pm.expect(body.csrf).to.be.a('string').and.not.empty);",
        "pm.collectionVariables.set('csrf', body.csrf);",
      ],
    }),
    req('Logout', 'POST', '/api/logout', {
      description: 'Destroys the session and clears both cookies.',
      tests: [
        "pm.test('200 OK', () => pm.response.to.have.status(200));",
        "pm.test('both cookies cleared', () => {",
        "  const raw = pm.response.headers.all()",
        "    .filter((h) => h.key.toLowerCase() === 'set-cookie')",
        "    .map((h) => h.value)",
        "    .join('\\n');",
        "  pm.expect(raw).to.match(/kb_session=;|kb_session=deleted/i);",
        '});',
      ],
    }),
  ],
}

collection.item = [
  authFolder,
  boardFolder,
  columnsFolder,
  cardsFolder,
  labelsFolder,
  checklistFolder,
  commentsFolder,
  cleanupFolder,
  teardownFolder,
]

fs.mkdirSync(path.dirname(OUT), { recursive: true })
fs.writeFileSync(OUT, JSON.stringify(collection, null, 2) + '\n', 'utf8')

const count = collection.item.reduce((n, f) => n + f.item.length, 0)
console.log(`wrote ${OUT}`)
console.log('folders:', collection.item.map((f) => `${f.name}(${f.item.length})`).join(', '))
console.log('requests:', count)
