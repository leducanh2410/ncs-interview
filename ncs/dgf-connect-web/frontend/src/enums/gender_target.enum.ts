export type GenderTarget = 'MALE' | 'FEMALE' | 'BOTH';

export const GenderTargetEnum: { [key in GenderTarget]: { value: string; text: string } } = Object.freeze({
    BOTH: { value: 'both', text: 'Both' },
    MALE: { value: 'male', text: 'Male' },
    FEMALE: { value: 'female', text: 'Female' },
});

/**
 * Upper bound for the nearby-workplace radius, in kilometres.
 * Mirrors StoreRequest::MAX_NEARBY_RADIUS_KM on the backend.
 */
export const MAX_NEARBY_RADIUS_KM = 200;

/** Pre-filled radius, matching the forecast screen's default. */
export const DEFAULT_NEARBY_RADIUS_KM = 10;
