/**
 * Test for PatternEditor component cleanup
 * 
 * This test verifies that the PatternEditor component has been properly cleaned up
 * after removing the 'code', 'style', and 'patterns' modes/tabs.
 */

describe('PatternEditor Component Cleanup', () => {
	it('should not contain references to removed editor modes', () => {
		// Read the PatternEditor component file
		const fs = require('fs');
		const path = require('path');
		const filePath = path.join(__dirname, '../../src/components/PatternEditor.js');
		const fileContent = fs.readFileSync(filePath, 'utf8');

		// Check that removed modes are not referenced in conditional rendering
		expect(fileContent).not.toMatch(/editorMode === 'code'/);
		expect(fileContent).not.toMatch(/editorMode === 'style'/);
		
		// Check that removed tab is not referenced
		expect(fileContent).not.toMatch(/tab\.name === 'patterns'/);
		
		// Check that PatternSearch import has been removed
		expect(fileContent).not.toMatch(/import PatternSearch/);
		
		// Verify that only valid editor modes are present
		expect(fileContent).toMatch(/editorMode === 'visual'/);
		expect(fileContent).toMatch(/editorMode === 'markup'/);
		
		// Verify that only valid tabs are present
		expect(fileContent).toMatch(/tab\.name === 'pattern'/);
		expect(fileContent).toMatch(/tab\.name === 'block'/);
		expect(fileContent).toMatch(/tab\.name === 'blocks'/);
		expect(fileContent).toMatch(/tab\.name === 'bindings'/);
	});

	it('should have the correct tab configuration', () => {
		const fs = require('fs');
		const path = require('path');
		const filePath = path.join(__dirname, '../../src/components/PatternEditor.js');
		const fileContent = fs.readFileSync(filePath, 'utf8');

		// Check that the tabs array contains only the expected tabs
		const tabsMatch = fileContent.match(/tabs=\{(\[[\s\S]*?\])\}/);
		if (tabsMatch) {
			const tabsContent = tabsMatch[1];
			
			// Should contain these tabs
			expect(tabsContent).toMatch(/name:\s*['"]pattern['"]/);
			expect(tabsContent).toMatch(/name:\s*['"]block['"]/);
			expect(tabsContent).toMatch(/name:\s*['"]blocks['"]/);
			expect(tabsContent).toMatch(/name:\s*['"]bindings['"]/);
			
			// Should NOT contain these removed tabs
			expect(tabsContent).not.toMatch(/name:\s*['"]patterns['"]/);
		}
	});

	it('should have the correct toggle group options', () => {
		const fs = require('fs');
		const path = require('path');
		const filePath = path.join(__dirname, '../../src/components/PatternEditor.js');
		const fileContent = fs.readFileSync(filePath, 'utf8');

		// Should contain visual and markup options
		expect(fileContent).toMatch(/value="visual"/);
		expect(fileContent).toMatch(/value="markup"/);
		
		// Should NOT contain code and style options
		expect(fileContent).not.toMatch(/value="code"/);
		expect(fileContent).not.toMatch(/value="style"/);
	});
});