const fs = require('fs');
const path = require('path');

const file = path.join(__dirname, 'resources/js/pages/Dashboard.vue');
let content = fs.readFileSync(file, 'utf8');

// Color Replacements (from WhatsApp dark theme to Monochrome minimal light theme)
const colorMap = {
    'bg-[#111b21]': 'bg-white',
    'bg-[#202c33]': 'bg-gray-50',
    'bg-[#222d34]': 'bg-gray-100',
    'bg-[#374045]': 'bg-gray-200',
    'bg-[#0b141a]': 'bg-white',
    'bg-[#1c272e]': 'bg-gray-50',
    'bg-[#2a3942]': 'bg-gray-100',
    'bg-[#00a884]': 'bg-black text-white',
    'bg-[#06cf9c]': 'bg-gray-800 text-white',
    'bg-[#005c4b]': 'bg-black text-white',
    'text-[#e9edef]': 'text-gray-900',
    'text-[#8696a0]': 'text-gray-500',
    'text-[#00a884]': 'text-black font-semibold',
    'border-[#222d34]': 'border-gray-200',
    'border-[#2a3942]': 'border-gray-200',
    'border-[#374045]': 'border-gray-300',
    'ring-[#00a884]': 'ring-black',
    'focus:ring-[#00a884]': 'focus:ring-black',
    'focus:border-[#00a884]': 'focus:border-black',
};

for (const [oldClass, newClass] of Object.entries(colorMap)) {
    content = content.split(oldClass).join(newClass);
}

// Custom manual overrides for specific elements
// Remove emoji from statuses
content = content.replace(/'🔵 NUEVO'/g, "'NUEVO'");
content = content.replace(/'🟡 INTERESADO'/g, "'INTERESADO'");
content = content.replace(/'🟠 CONSULTANDO'/g, "'CONSULTANDO'");
content = content.replace(/'🟣 ESPERANDO PAGO'/g, "'ESPERANDO PAGO'");
content = content.replace(/'🟠 VERIFICARYAPE'/g, "'VERIFICARYAPE'");
content = content.replace(/'🟢 PAGO RECIBIDO'/g, "'PAGO RECIBIDO'");
content = content.replace(/'🟢 VENTA CUMPLIDA'/g, "'VENTA CUMPLIDA'");
content = content.replace(/'🔴 NECESITA ASESOR'/g, "'NECESITA ASESOR'");

// Priority icons (replace with minimal dots)
content = content.replace(/'🔴'/g, "'●'");
content = content.replace(/'🟡'/g, "'◐'");
content = content.replace(/'⚪'/g, "'○'");

// Replace Emojis in strings/templates
const emojiReplacements = [
    { regex: /💰/g, replacement: '<svg class="w-4 h-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>' },
    { regex: /📸 /g, replacement: '' }, // Just remove it, or use SVG. Let's just remove most to keep it extremely clean.
    { regex: /🔥 /g, replacement: '' },
    { regex: /🏷️ /g, replacement: '' },
    { regex: /📝 /g, replacement: '' },
    { regex: /🛍️ /g, replacement: '' },
    { regex: /🛒 /g, replacement: '' },
    { regex: /✅ /g, replacement: '' },
    { regex: /❌ /g, replacement: '' },
    { regex: /🤖 /g, replacement: 'AI ' },
];

for (const item of emojiReplacements) {
    content = content.replace(item.regex, item.replacement);
}

// Adjust status colors from vibrant to monochrome variants (e.g. gray shades or subtle borders instead of solid red/green)
// Wait, Tailwind colors are hardcoded.
content = content.replace(/'bg-blue-500'/g, "'bg-gray-300'");
content = content.replace(/'bg-yellow-500'/g, "'bg-gray-300'");
content = content.replace(/'bg-orange-500'/g, "'bg-gray-400'");
content = content.replace(/'bg-purple-500'/g, "'bg-gray-500'");
content = content.replace(/'bg-amber-500'/g, "'bg-gray-600'");
content = content.replace(/'bg-green-500'/g, "'bg-black'");
content = content.replace(/'bg-red-500'/g, "'bg-gray-800'");

// Remove the colorful buttons (Validar Pago, etc.) and make them minimalist
content = content.replace(/bg-\[#3b82f6\]/g, "bg-gray-800");
content = content.replace(/hover:bg-\[#60a5fa\]/g, "hover:bg-gray-700");
content = content.replace(/bg-\[#10b981\]/g, "bg-black");
content = content.replace(/hover:bg-\[#059669\]/g, "hover:bg-gray-800");
content = content.replace(/bg-\[#22c55e\]/g, "bg-black");
content = content.replace(/hover:bg-\[#16a34a\]/g, "hover:bg-gray-800");
content = content.replace(/bg-\[#ef4444\]/g, "bg-gray-600");
content = content.replace(/hover:bg-\[#dc2626\]/g, "hover:bg-gray-500");

// Update pulse animations to use black/gray
content = content.replace(/bg-\[#22c55e\]\/20/g, "bg-gray-200");
content = content.replace(/text-\[#22c55e\]/g, "text-gray-800");
content = content.replace(/bg-\[#ef4444\]\/20/g, "bg-gray-200");
content = content.replace(/text-\[#ef4444\]/g, "text-gray-800");
content = content.replace(/bg-\[#f59e0b\]\/20/g, "bg-gray-200");
content = content.replace(/text-\[#f59e0b\]/g, "text-gray-800");

// Also replace the LogoRomaStore.png with professional monochrome aesthetic
// Actually, the prompt says "usa iconos que se vea profesional"

fs.writeFileSync(file, content, 'utf8');
console.log('Done transforming Dashboard.vue');
