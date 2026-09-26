import type { Tone } from '@/config/labels';

export const TONE_CHIP: Record<Tone, string> = {
  green: 'bg-tone-green-soft text-tone-green',
  blue: 'bg-tone-blue-soft text-tone-blue',
  yellow: 'bg-tone-yellow-soft text-tone-yellow',
  red: 'bg-tone-red-soft text-tone-red',
  gray: 'bg-tone-gray-soft text-tone-gray',
  orange: 'bg-tone-orange-soft text-tone-orange',
};

export const TONE_TEXT: Record<Tone, string> = {
  green: 'text-tone-green',
  blue: 'text-tone-blue',
  yellow: 'text-tone-yellow',
  red: 'text-tone-red',
  gray: 'text-tone-gray',
  orange: 'text-tone-orange',
};

export const TONE_MARK: Record<Tone, string> = {
  green: 'bg-mark-green',
  blue: 'bg-mark-blue',
  yellow: 'bg-mark-yellow',
  red: 'bg-mark-red',
  gray: 'bg-mark-gray',
  orange: 'bg-mark-orange',
};

export const TONE_VAR: Record<Tone, string> = {
  green: 'var(--m-green)',
  blue: 'var(--m-blue)',
  yellow: 'var(--m-yellow)',
  red: 'var(--m-red)',
  gray: 'var(--m-gray)',
  orange: 'var(--m-orange)',
};
