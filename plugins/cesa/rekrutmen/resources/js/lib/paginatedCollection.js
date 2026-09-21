import axios from 'axios';

const pendingCollections = new WeakMap();

export function fetchPaginatedCollection(store, {
  collection, url, params, recordsKey, force, errorMessage, onLoaded, onClear,
}) {
  if (!pendingCollections.has(store)) pendingCollections.set(store, new Map());
  const requests = pendingCollections.get(store);
  const queryKey = JSON.stringify(params);
  const pending = requests.get(collection);

  if (!force && pending?.queryKey === queryKey) return pending.promise;
  if (!force && !pending && store.loadedCollections[collection] === queryKey) {
    return Promise.resolve(store[collection]);
  }

  if (store.collectionQueries[collection] !== queryKey) {
    store[collection] = [];
    onClear?.();
  }
  store.collectionQueries[collection] = queryKey;
  store.loadedCollections[collection] = null;
  store.loading[collection] = true;
  store.errors[collection] = '';

  const request = { queryKey, promise: null };
  requests.set(collection, request);

  request.promise = (async () => {
    try {
      const records = [];
      let metadata;
      let page = 1;
      let lastPage = 1;

      do {
        const { data } = await axios.get(url, { params: { ...params, page, per_page: 100 } });
        if (requests.get(collection) !== request) return store[collection];

        const pageRecords = Array.isArray(data) ? data : data?.[recordsKey];
        if (!Array.isArray(pageRecords)) throw new Error('Invalid collection response');

        records.push(...pageRecords.filter(Boolean));
        if (page === 1) metadata = data;
        lastPage = Number(data?.last_page ?? 1);
        if (!Number.isInteger(lastPage) || lastPage < 1) throw new Error('Invalid pagination response');
        page++;
      } while (page <= lastPage);

      store[collection] = records;
      onLoaded?.(metadata);
      store.loadedCollections[collection] = queryKey;
      return store[collection];
    } catch (error) {
      if (requests.get(collection) !== request) return store[collection];
      store.errors[collection] = errorMessage;
      throw error;
    } finally {
      if (requests.get(collection) === request) {
        requests.delete(collection);
        store.loading[collection] = false;
      }
    }
  })();

  return request.promise;
}
