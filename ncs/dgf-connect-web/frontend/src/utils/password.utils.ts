export const generateRandomPassword = (length = 8): string => {
    const charset = '0123456789';
    const randomValues = new Uint32Array(length);

    window.crypto.getRandomValues(randomValues);

    return Array.from(randomValues)
        .map((value) => charset[value % charset.length])
        .join('');
};
