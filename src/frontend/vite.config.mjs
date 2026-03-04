import fs from "node:fs";
import path from "node:path";
import { defineConfig } from "vite";
import {
    buildEntryPoints,
    createAssetFileNameBuilder,
    createBaseViteConfig,
    createCssConfig,
    outDir,
    resolveFromRoot,
    toPosix,
    trackedOutputDirs as outputDirs,
    walkRelativeFiles,
} from "./vite.config.common.mjs";

const entryPoints = buildEntryPoints();
const assetFileNameBuilder = createAssetFileNameBuilder("[hash:16][extname]");

const removeStaleEntries = (targetDir, keepFiles, rootDir = targetDir) => {
    if (!fs.existsSync(targetDir)) {
        return;
    }

    for (const entry of fs.readdirSync(targetDir, { withFileTypes: true })) {
        const fullPath = path.resolve(targetDir, entry.name);
        if (entry.isDirectory()) {
            removeStaleEntries(fullPath, keepFiles, rootDir);
            if (fs.readdirSync(fullPath).length === 0) {
                fs.rmSync(fullPath, { recursive: true, force: true });
            }
            continue;
        }

        const relativePath = toPosix(path.relative(rootDir, fullPath));
        if (!keepFiles.has(relativePath)) {
            fs.rmSync(fullPath, { force: true });
        }
    }
};

const manifestAndCleanupPlugin = () => {
    let generatedFiles = new Set();

    return {
        name: "manifest-and-cleanup",
        generateBundle(_, bundle) {
            const manifest = {};

            for (const [fileName, output] of Object.entries(bundle)) {
                if (output.type === "chunk") {
                    if (!output.isEntry) {
                        continue;
                    }

                    manifest[`${output.name}.js`] = fileName;
                    for (const cssFile of output.viteMetadata?.importedCss ?? []) {
                        manifest[`${output.name}.css`] = cssFile;
                    }
                    continue;
                }

                if (output.name) {
                    manifest[output.name] = fileName;
                }
            }

            generatedFiles = new Set(Object.keys(bundle));
            this.emitFile({
                type: "asset",
                fileName: "manifest.json",
                source: `${JSON.stringify(manifest, null, 2)}\n`,
            });
        },
        closeBundle() {
            if (!generatedFiles.size) {
                return;
            }

            const sourceImagesDir = resolveFromRoot("images");
            const targetImagesDir = path.resolve(outDir, "images");
            if (!fs.existsSync(sourceImagesDir)) {
                fs.rmSync(targetImagesDir, { recursive: true, force: true });
            } else {
                fs.mkdirSync(targetImagesDir, { recursive: true });
                fs.cpSync(sourceImagesDir, targetImagesDir, { recursive: true, force: true });
            }

            const keepFilesByDir = Object.fromEntries(outputDirs.map((dir) => [dir, new Set()]));
            for (const filePath of generatedFiles) {
                const normalized = toPosix(filePath);
                const firstSlashIndex = normalized.indexOf("/");
                if (firstSlashIndex === -1) {
                    continue;
                }

                const dirName = normalized.slice(0, firstSlashIndex);
                if (keepFilesByDir[dirName]) {
                    keepFilesByDir[dirName].add(normalized.slice(firstSlashIndex + 1));
                }
            }

            for (const filePath of walkRelativeFiles(sourceImagesDir)) {
                keepFilesByDir.images.add(filePath);
            }

            for (const dirName of outputDirs) {
                removeStaleEntries(path.resolve(outDir, dirName), keepFilesByDir[dirName]);
            }
        },
    };
};

export default defineConfig(createBaseViteConfig({
    plugins: [manifestAndCleanupPlugin()],
    esbuild: {
        pure: [
            "console.log",
        ],
    },
    build: {
        sourcemap: false,
        rollupOptions: {
            input: entryPoints,
            output: {
                entryFileNames: "js/[hash:16].js",
                chunkFileNames: "js/chunks/[hash:16].js",
                assetFileNames: assetFileNameBuilder,
            },
        },
    },
    css: createCssConfig(),
}));
