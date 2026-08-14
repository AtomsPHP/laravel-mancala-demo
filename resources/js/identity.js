const STORAGE_KEY = 'atoms-mancala-client-id';

export function browserId(storage = window.localStorage, cryptoApi = window.crypto) {
  const existing = storage.getItem(STORAGE_KEY);
  if (existing) return existing;

  const id = cryptoApi.randomUUID();
  storage.setItem(STORAGE_KEY, id);
  return id;
}
