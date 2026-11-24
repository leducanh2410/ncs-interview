import type { ParsedUrlQuery } from "@/helpers/api.helper";

const CommonHelper = {
  replaceUrlParam: (url: string, params: Record<string, string | number>) => {
    let newUrl: string = url;
    for (const key of Object.keys(params)) {
      let value = params[key];
      if (typeof value === "number") {
        value = value.toString();
      }
      newUrl = newUrl.replace(`:${key}`, value);
    }
    return newUrl;
  },

  urlQueryToString: (query: ParsedUrlQuery) => {
    const parts = [];
    for (let field of Object.keys(query)) {
      const value = query[field];
      if (value === undefined) {
        continue;
      }
      if (Array.isArray(value)) {
        field = `${field}`; // field = `${field}[]`; match with BACKEND
        for (const v of value) {
          parts.push(`${field}=${v}`);
        }
      } else {
        parts.push(`${field}=${value}`);
      }
    }
    return parts.join("&");
  },
};

export default CommonHelper;
