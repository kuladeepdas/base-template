import fs from "node:fs";
import path from "node:path";
import { ESLint } from "eslint";
import stylelint from "stylelint";
import { defineConfig } from "vite";
import {
    buildEntryPoints,
    createAssetFileNameBuilder,
    createBaseViteConfig,
    createCssConfig,
    outDir,
    pruneEmptyDirs,
    root,
    srcDir,
    trackedOutputDirs,
    walkFiles,
} from "./vite.config.common.mjs";

const indexEntryFile = path.resolve(srcDir, "index.js");
const cssAppPrefix = "src/css/app/";

const normalizePath = (filePath) => filePath.replace(/\\/g, "/");
const isLocalStyleImport = (importPath) =>
    (importPath.startsWith(".") || importPath.startsWith("/")) && /\.(scss|css)$/i.test(importPath);

const deriveSplitEntryName = (relativeSourceFile) => {
    const ext = path.extname(relativeSourceFile);
    const withoutExt = relativeSourceFile.slice(0, -ext.length);

    if (withoutExt.startsWith(cssAppPrefix)) {
        return withoutExt.slice(cssAppPrefix.length);
    }

    if (withoutExt.startsWith("icons/")) {
        return withoutExt;
    }

    const segments = withoutExt.split("/");
    const fileName = segments[segments.length - 1];
    const parentDir = segments.length > 1 ? segments[segments.length - 2] : "styles";
    return `${parentDir}/${fileName}`;
};

const uniqueName = (preferredName, usedNames) => {
    if (!usedNames.has(preferredName)) {
        return preferredName;
    }

    let suffix = 2;
    while (usedNames.has(`${preferredName}-${suffix}`)) {
        suffix += 1;
    }
    return `${preferredName}-${suffix}`;
};

const getSplitStyleConfig = () => {
    const splitEntries = {};
    const splitSources = [];

    if (!fs.existsSync(indexEntryFile)) {
        return { splitEntries, splitSources };
    }

    const sourceCode = fs.readFileSync(indexEntryFile, "utf8");
    const importRegex = /import\s+(?:[\s\S]*?\s+from\s+)?["']([^"']+)["']/g;
    const usedNames = new Set();
    const seenSources = new Set();

    for (const match of sourceCode.matchAll(importRegex)) {
        const importPath = match[1];
        if (!isLocalStyleImport(importPath)) {
            continue;
        }

        const resolvedPath = path.resolve(path.dirname(indexEntryFile), importPath);
        if (!resolvedPath.startsWith(root) || !fs.existsSync(resolvedPath)) {
            continue;
        }

        const sourceFile = normalizePath(path.relative(root, resolvedPath));
        if (seenSources.has(sourceFile)) {
            continue;
        }

        const preferredEntryName = deriveSplitEntryName(sourceFile);
        const entryName = uniqueName(preferredEntryName, usedNames);

        usedNames.add(entryName);
        seenSources.add(sourceFile);
        splitEntries[entryName] = resolvedPath;
        splitSources.push(sourceFile);
    }

    return { splitEntries, splitSources };
};

const formatCheckFailure = (title, issues) => {
    const useColor = process.stdout.isTTY;
    const color = (code, text) => (useColor ? `\u001b[${code}m${text}\u001b[0m` : text);
    const dim = (text) => color("90", text);
    const warn = (text) => color("1;33", text);
    const info = (text) => color("36", text);

    const shown = issues.slice(0, 20);
    const hiddenCount = Math.max(issues.length - shown.length, 0);
    const lines = [
        "",
        dim(`==================== ${title} ====================`),
        warn(`Errors: ${issues.length}`),
        "",
        ...shown.map((issue, index) => {
            const [location, message = ""] = issue.split("\n");
            return `${info(`${index + 1}. ${location}`)}\n${dim(message.trim())}`;
        }),
    ];

    if (hiddenCount > 0) {
        lines.push("", warn(`...and ${hiddenCount} more error(s).`));
    }

    lines.push(dim("============================================================"));
    return lines.join("\n");
};

const createCheckPlugin = (name, runCheck) => ({
    name,
    async buildStart() {
        const issues = await runCheck();
        if (issues.length > 0) {
            throw new Error(formatCheckFailure(`${name} FAILED`, issues));
        }
    },
});

const stylelintCheckPlugin = () =>
    createCheckPlugin("STYLE CHECK", async () => {
        const lintResult = await stylelint.lint({
            files: ["src/css/**/*.scss", "icons/**/*.scss"],
            configFile: path.resolve(root, "stylelint.config.mjs"),
        });

        if (!lintResult.errored) {
            return [];
        }

        const issues = [];
        for (const result of lintResult.results || []) {
            for (const warning of result.warnings || []) {
                issues.push(
                    `${result.source}:${warning.line}:${warning.column}\n   ${warning.text} [${warning.rule}]`,
                );
            }
        }

        return issues;
    });

const eslintCheckPlugin = () =>
    createCheckPlugin("SCRIPT CHECK", async () => {
        const eslint = new ESLint({ cwd: root });
        const results = await eslint.lintFiles(["src/js/**/*.js"]);
        const errorCount = results.reduce((sum, result) => sum + result.errorCount, 0);

        if (errorCount === 0) {
            return [];
        }

        const issues = [];
        for (const result of results) {
            for (const message of result.messages || []) {
                if (message.severity === 2) {
                    issues.push(
                        `${result.filePath}:${message.line}:${message.column}\n   ${message.message} [${message.ruleId || "unknown"}]`,
                    );
                }
            }
        }

        return issues;
    });

const buildManifestPlugin = (splitStyleSourceFiles) => {
    const isLocalSourceModule = (moduleId) => moduleId.startsWith(root) && !moduleId.includes("/node_modules/");

    return {
        name: "webpack-style-manifest",
        generateBundle(_, bundle) {
            const manifest = {};

            for (const [fileName, output] of Object.entries(bundle)) {
                if (output.type !== "chunk") {
                    if (output.name) {
                        manifest[output.name] = fileName;
                    }
                    continue;
                }

                const cssFiles = output.viteMetadata?.importedCss
                    ? Array.from(output.viteMetadata.importedCss)
                    : [];
                const primaryCssFile = cssFiles[0] || null;
                const moduleIds = output.moduleIds || Object.keys(output.modules || {});

                for (const moduleId of moduleIds) {
                    if (!moduleId) {
                        continue;
                    }

                    const cleanModuleId = moduleId.split("?")[0];
                    if (!isLocalSourceModule(cleanModuleId)) {
                        continue;
                    }

                    const sourceKey = normalizePath(path.relative(root, cleanModuleId));
                    const isStyleSource = /\.(scss|css)$/i.test(cleanModuleId);
                    const mappedFile = isStyleSource && primaryCssFile ? primaryCssFile : fileName;
                    const existingFile = manifest[sourceKey];
                    const preferSplitStyle =
                        Boolean(existingFile) &&
                        isStyleSource &&
                        existingFile.endsWith("index.css") &&
                        mappedFile !== existingFile;

                    if (!existingFile || preferSplitStyle) {
                        manifest[sourceKey] = mappedFile;
                    }
                }

                if (output.isEntry) {
                    manifest[`${output.name}.js`] = fileName;
                    for (const cssFile of cssFiles) {
                        manifest[`${output.name}.css`] = cssFile;
                    }
                }
            }

            for (const fileName of Object.keys(bundle)) {
                if (fileName.endsWith(".map")) {
                    manifest[fileName] = fileName;
                }
            }

            const imports = splitStyleSourceFiles
                .map((sourceFile) => manifest[sourceFile])
                .filter((cssFile) => cssFile && cssFile.endsWith(".css"))
                .map((cssFile) =>
                    cssFile.startsWith("css/")
                        ? `@import "./${cssFile.slice(4)}";`
                        : `@import "../${cssFile}";`,
                );

            if (imports.length > 0) {
                manifest["index.css"] = "css/index.css";
                bundle["css/index.css"] = {
                    type: "asset",
                    fileName: "css/index.css",
                    name: "index.css",
                    source: `${imports.join("\n")}\n`,
                };
            }

            this.emitFile({
                type: "asset",
                fileName: "manifest.json",
                source: `${JSON.stringify(manifest, null, 2)}\n`,
            });
        },
    };
};

const copyStaticImagesPlugin = () => ({
    name: "copy-static-images",
    closeBundle() {
        const sourceDir = path.resolve(root, "images");
        const targetDir = path.resolve(outDir, "images");
        if (!fs.existsSync(sourceDir)) {
            return;
        }

        fs.mkdirSync(targetDir, { recursive: true });
        fs.cpSync(sourceDir, targetDir, { recursive: true, force: true });
    },
});

const removeStaleAssetsOnSuccessPlugin = () => {
    let previousFiles = new Set();
    let currentBuildFiles = new Set();

    return {
        name: "remove-stale-assets-on-success",
        buildStart() {
            previousFiles = new Set(
                trackedOutputDirs.flatMap((dirName) => walkFiles(path.resolve(outDir, dirName))),
            );
            currentBuildFiles = new Set();
        },
        writeBundle(_, bundle) {
            currentBuildFiles = new Set(
                Object.keys(bundle)
                    .map((fileName) => normalizePath(fileName))
                    .filter((fileName) => trackedOutputDirs.some((dirName) => fileName.startsWith(`${dirName}/`)))
                    .map((fileName) => path.resolve(outDir, fileName)),
            );
        },
        closeBundle() {
            const currentFiles = new Set(currentBuildFiles);
            const sourceImagesDir = path.resolve(root, "images");

            if (fs.existsSync(sourceImagesDir)) {
                for (const sourceImagePath of walkFiles(sourceImagesDir)) {
                    const relativePath = normalizePath(path.relative(sourceImagesDir, sourceImagePath));
                    currentFiles.add(path.resolve(outDir, "images", relativePath));
                }
            }

            for (const oldFilePath of previousFiles) {
                if (!currentFiles.has(oldFilePath)) {
                    fs.rmSync(oldFilePath, { force: true });
                }
            }

            for (const dirName of trackedOutputDirs) {
                pruneEmptyDirs(path.resolve(outDir, dirName));
            }
        },
    };
};

export default defineConfig(({ command }) => {
    const entryPoints = buildEntryPoints(indexEntryFile);
    const { splitEntries, splitSources } = getSplitStyleConfig();
    const inputEntries = { ...entryPoints, ...splitEntries };

    return createBaseViteConfig({
        plugins: [
            ...(command === "build" ? [removeStaleAssetsOnSuccessPlugin()] : []),
            stylelintCheckPlugin(),
            eslintCheckPlugin(),
            buildManifestPlugin(splitSources),
            copyStaticImagesPlugin(),
        ],
        build: {
            minify: false,
            sourcemap: true,
            rollupOptions: {
                input: inputEntries,
                output: {
                    entryFileNames: "js/[name].js",
                    chunkFileNames: "js/chunks/[name].js",
                    assetFileNames: createAssetFileNameBuilder("[name][extname]"),
                },
            },
        },
        css: createCssConfig({
            devSourcemap: true,
            postcssMap: { inline: true, annotation: true },
            scssSourceMap: true,
        }),
    });
});
