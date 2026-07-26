export function correctTranscriptMemberNameCasing(text, memberNames = []) {
    if (!memberNames.length) {
        return text;
    }

    const nameTokens = new Map();

    for (const fullName of memberNames) {
        for (const part of fullName.split(/\s+/)) {
            const token = part.trim();

            if (token) {
                nameTokens.set(token.toLowerCase(), token);
            }
        }
    }

    return text.replace(/\b[A-Za-z]{3,}\b/g, (word) => {
        return nameTokens.get(word.toLowerCase()) ?? word;
    });
}
