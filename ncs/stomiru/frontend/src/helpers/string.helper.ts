const StringHelper = {
  toPhoneNumber: (value: string) => {
    // Remove all non-digit characters
    const digits = value.replace(/\D/g, "");
    // Format the digits as a phone number (e.g., 123-456-7890)
    const formatted = digits.replace(/(\d{3})(\d{3})(\d{4})/, "$1-$2-$3");
    return formatted;
  },
  generatePassword: (length: number = 12) => {
    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*";
    let result = "";
    for (let i = 0; i < length; i++) {
      result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
  },

  splitLabel: (text: string, maxLength: number) => {
    const words = text.split(" ");
    const lines = [];
    let current = "";

    for (let word of words) {
      while (word.length > maxLength) {
        lines.push(word.slice(0, maxLength));
        word = word.slice(maxLength);
      }

      if ((current + " " + word).trim().length <= maxLength) {
        current = (current + " " + word).trim();
      } else {
        if (current) lines.push(current);
        current = word;
      }
    }

    if (current) lines.push(current);

    return lines;
  },
};

export default StringHelper;
