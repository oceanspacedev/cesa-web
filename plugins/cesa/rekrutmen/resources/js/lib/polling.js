export function createPoller({ request, onData, onError, onTimeout, interval = 2000, maxDuration = 300000 }) {
  let timer = null;
  let controller = null;
  let generation = 0;

  const stop = () => {
    generation += 1;
    clearTimeout(timer);
    controller?.abort();
    controller = null;
  };

  const start = (key) => {
    stop();
    const current = generation;
    const deadline = Date.now() + maxDuration;

    const tick = async () => {
      if (current !== generation) return;
      if (Date.now() >= deadline) {
        stop();
        onTimeout?.();
        return;
      }

      controller = new AbortController();
      let keepPolling = true;
      try {
        const data = await request(key, controller.signal);
        if (current !== generation) return;
        keepPolling = onData(data) !== false;
      } catch (error) {
        if (current !== generation) return;
        keepPolling = onError?.(error) !== false;
      }

      if (current !== generation) return;
      if (!keepPolling) {
        stop();
        return;
      }
      timer = setTimeout(tick, interval);
    };

    timer = setTimeout(tick, interval);
  };

  return { start, stop };
}
