export const TOP_PITS = Object.freeze([11, 10, 9, 8, 7, 6]);
export const BOTTOM_PITS = Object.freeze([0, 1, 2, 3, 4, 5]);

export function canPlay(state, seat, pit, animating = false) {
  if (!state || animating || state.status !== 'active' || state.turn !== seat) return false;
  if (seat === 0 && (pit < 0 || pit > 5)) return false;
  if (seat === 1 && (pit < 6 || pit > 11)) return false;
  return (state.pits?.[pit] ?? 0) > 0;
}

export function beginAnimatedMove(state, sourcePit) {
  const copy = structuredClone(state);
  if (copy.pits?.[sourcePit] !== undefined) copy.pits[sourcePit] = 0;
  return copy;
}

export function applyDrop(state, destination) {
  const copy = structuredClone(state);
  if (destination.kind === 'pit') copy.pits[destination.index] += 1;
  if (destination.kind === 'store') copy.stores[destination.player] += 1;
  return copy;
}

export function eventFromFrame(frame) {
  if (frame?.kind === 'broadcast' && frame.payload) return frame.payload;
  return frame;
}
