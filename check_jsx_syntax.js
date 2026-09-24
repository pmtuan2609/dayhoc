const fs = require('fs');

function checkFile(path) {
    const content = fs.readFileSync(path, 'utf-8');
    // Extract the React app script block
    const match = content.match(/<script id="react-app-source" type="text\/plain">([\s\S]*?)<\/script>/);
    if (!match) {
        console.log(`No plain script tag found in ${path}, trying text/babel`);
        const matchBabel = content.match(/<script type="text\/babel">([\s\S]*?)<\/script>/);
        if (!matchBabel) {
            console.log(`No script tag found in ${path}`);
            return;
        }
        runBabelCheck(matchBabel[1], path);
    } else {
        runBabelCheck(match[1], path);
    }
}

function runBabelCheck(code, path) {
    // We can use acorn or a simple parser to check syntax
    // Let's use acorn which is built into Node's internal modules or npm
    try {
        const acorn = require('acorn');
        // Acorn doesn't support JSX by default, but we can check if it parses basic JS
        // Or we can install acorn-jsx.
        // Let's check if we can parse it using esprima if available, or just check brackets.
        console.log(`Checking ${path} script block of length ${code.length}...`);
    } catch (e) {
        // Acorn not found, let's write a simple bracket matching count
    }

    // Let's count open/close braces and brackets
    let braces = 0;
    let brackets = 0;
    let parens = 0;
    let inString = false;
    let stringChar = '';
    let inComment = false;
    let commentType = ''; // 'single' or 'multi'

    for (let i = 0; i < code.length; i++) {
        const char = code[i];
        const nextChar = code[i+1];

        if (inComment) {
            if (commentType === 'single' && char === '\n') {
                inComment = false;
            } else if (commentType === 'multi' && char === '*' && nextChar === '/') {
                inComment = false;
                i++;
            }
            continue;
        }

        if (inString) {
            if (char === stringChar && code[i-1] !== '\\') {
                inString = false;
            }
            continue;
        }

        if (char === '/' && nextChar === '/') {
            inComment = true;
            commentType = 'single';
            i++;
            continue;
        }
        if (char === '/' && nextChar === '*') {
            inComment = true;
            commentType = 'multi';
            i++;
            continue;
        }

        if (char === '"' || char === "'" || char === '`') {
            inString = true;
            stringChar = char;
            continue;
        }

        if (char === '{') braces++;
        if (char === '}') braces--;
        if (char === '[') brackets++;
        if (char === ']') brackets--;
        if (char === '(') parens++;
        if (char === ')') parens--;

        if (braces < 0) {
            console.log(`Error: Unmatched closing brace '}' at position ${i} around: "${code.substring(max(0, i-30), i+30)}"`);
            return;
        }
    }

    console.log(`Brackets audit for ${path}: braces=${braces}, brackets=${brackets}, parens=${parens}`);
    if (braces !== 0) console.log(`Warning: unmatched braces in ${path}!`);
    if (brackets !== 0) console.log(`Warning: unmatched brackets in ${path}!`);
    if (parens !== 0) console.log(`Warning: unmatched parens in ${path}!`);
}

checkFile('/Users/vietha/Documents/web_thaytuan/admin.php');
checkFile('/Users/vietha/Documents/web_thaytuan/admin_thaytuan.php');
checkFile('/Users/vietha/Documents/web_thaytuan/index.php');
checkFile('/Users/vietha/Documents/web_thaytuan/index_thaytuan.php');
