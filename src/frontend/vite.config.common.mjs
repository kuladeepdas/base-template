import fs from "node:fs";
import path from "node:path";
import autoprefixer from "autoprefixer";

export const root = path.resolve(".");
export const resolveFromRoot = (...parts) => path.resolve(root, ...parts);
export const toPosix = (value) => value.split(path.sep).join("/");

export const srcDir = resolveFromRoot("src");
export const pagesDir = resolveFromRoot("src/js/pages");
export const outDir = resolveFromRoot("../web");
export const trackedOutputDirs = ["css", "js", "fonts", "images"];

const imageExtensions = /\.(png|jpe?g|gif|svg|ico|webp|avif)$/;
const fontExtensions = /\.(woff2?|eot|ttf|otf)$/;

export const scssGlobals = [
    `@import "${resolveFromRoot("src/css/resources/variables.scss")}";`,
    `@import "${resolveFromRoot("src/css/resources/mixins.scss")}";`,
    `@import "bootstrap/scss/_mixins.scss";`,
].join("\n");

const globalJqueryRuntimeCode = `
import $ from "jquery";

window.$ = $;
window.jQuery = $;

// jQuery 4 removed $.type; keep a small compatibility shim for older plugins.
if (typeof $.type !== "function") {
    $.type = (value) => {
        if (value == null) {
            return String(value);
        }
        return Object.prototype.toString.call(value).slice(8, -1).toLowerCase();
    };
}
`.trim();

export const createGlobalJqueryRuntimePlugin = () => {
    const entryPattern = /\/src\/(index\.js|js\/pages\/[^/]+\.js)$/;

    return {
        name: "global-jquery-runtime",
        transform(code, id) {
            const cleanId = id.split("?")[0].replace(/\\/g, "/");
            if (!entryPattern.test(cleanId)) {
                return null;
            }

            return {
                code: `${globalJqueryRuntimeCode}\n\n${code}`,
                map: null,
            };
        },
    };
};

export const buildEntryPoints = (indexEntry = resolveFromRoot("src/index.js")) => {
    const entryPoints = { index: indexEntry };

    if (fs.existsSync(pagesDir)) {
        for (const fileName of fs.readdirSync(pagesDir)) {
            if (fileName.endsWith(".js")) {
                entryPoints[`pages/${fileName.slice(0, -3)}`] = path.resolve(pagesDir, fileName);
            }
        }
    }

    return entryPoints;
};

export const createAssetFileNameBuilder = (nameTemplate) => {
    const extensionTargets = [
        { pattern: /^\.css$/, dir: "css" },
        { pattern: imageExtensions, dir: "images" },
        { pattern: fontExtensions, dir: "fonts" },
    ];

    return (assetInfo) => {
        const ext = path.extname(assetInfo.name || "").toLowerCase();
        const target = extensionTargets.find(({ pattern }) => pattern.test(ext));
        return `${target ? target.dir : "assets"}/${nameTemplate}`;
    };
};

export const walkFiles = (dirPath) => {
    if (!fs.existsSync(dirPath)) {
        return [];
    }

    const files = [];
    for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
        const fullPath = path.resolve(dirPath, entry.name);
        if (entry.isDirectory()) {
            files.push(...walkFiles(fullPath));
        } else if (entry.isFile()) {
            files.push(fullPath);
        }
    }

    return files;
};

export const walkRelativeFiles = (dirPath, baseDir = dirPath, files = []) => {
    if (!fs.existsSync(dirPath)) {
        return files;
    }

    for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
        const fullPath = path.resolve(dirPath, entry.name);
        if (entry.isDirectory()) {
            walkRelativeFiles(fullPath, baseDir, files);
            continue;
        }
        files.push(toPosix(path.relative(baseDir, fullPath)));
    }

    return files;
};

export const pruneEmptyDirs = (dirPath) => {
    if (!fs.existsSync(dirPath) || !fs.statSync(dirPath).isDirectory()) {
        return;
    }

    for (const entry of fs.readdirSync(dirPath, { withFileTypes: true })) {
        if (entry.isDirectory()) {
            pruneEmptyDirs(path.resolve(dirPath, entry.name));
        }
    }

    if (fs.readdirSync(dirPath).length === 0) {
        fs.rmdirSync(dirPath);
    }
};

export const createCssConfig = ({ devSourcemap = false, postcssMap, scssSourceMap = false } = {}) => ({
    ...(devSourcemap ? { devSourcemap: true } : {}),
    postcss: {
        ...(postcssMap ? { map: postcssMap } : {}),
        plugins: [autoprefixer()],
    },
    preprocessorOptions: {
        scss: {
            additionalData: `${scssGlobals}\n`,
            ...(scssSourceMap ? { sourceMap: true } : {}),
            quietDeps: true,
            silenceDeprecations: ["import", "global-builtin", "color-functions", "if-function"],
        },
    },
});

export const createBaseViteConfig = ({ plugins = [], build = {}, css, ...rest } = {}) => ({
    base: "/",
    publicDir: false,
    plugins: [createGlobalJqueryRuntimePlugin(), ...plugins],
    build: {
        outDir,
        emptyOutDir: false,
        ...build,
    },
    css: css || createCssConfig(),
    ...rest,
});
