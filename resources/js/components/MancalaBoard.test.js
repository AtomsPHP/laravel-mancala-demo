import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MancalaBoard from './MancalaBoard.vue';

const state = {
  status: 'active',
  pits: Array(12).fill(4),
  stores: [0, 0],
  turn: 0,
  revision: 0,
};

describe('MancalaBoard', () => {
  it('offers exactly the six current-player pits and emits their physical index', async () => {
    const wrapper = mount(MancalaBoard, { props: { state, seat: 0 } });
    const enabled = wrapper.findAll('button:not([disabled])');

    expect(enabled).toHaveLength(6);
    await enabled[2].trigger('click');
    expect(wrapper.emitted('move')[0]).toEqual([2]);
  });

  it('is read-only for an observer', () => {
    const wrapper = mount(MancalaBoard, { props: { state, seat: null } });
    expect(wrapper.findAll('button:not([disabled])')).toHaveLength(0);
  });
});
