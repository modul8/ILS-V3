import sys
import os
import glob

def confirm_overwrite(filename):
    '''Ask user if they want to overwrite a file'''
    response = input(f'\n{filename} already exists. Overwrite? [Y/N]: ').strip().upper()
    return response in ['Y', 'YES', 'YEAH']

def main():
    # Set the working directory
    os.chdir(r'C:\\Projects\\drain-map-pipeline')
    
    input_folder = "input_pdfs"
    output_folder = "outputs"
    os.makedirs(output_folder, exist_ok=True)
    
    if not os.path.isdir(input_folder):
        print("Error: Input directory does not exist")
        sys.exit(1)
    
    pdf_files = [f for f in os.listdir(input_folder) if f.endswith(".pdf")]
    
    if not pdf_files:
        print("Warning: No PDF files found")
        return
    
    print("Found PDF files")
    
    for pdf_file in pdf_files:
        pdf_file_path = os.path.join(input_folder, pdf_file)
        print(f"\nProcessing: {pdf_file}")
        
        import fitz
        doc = fitz.open(pdf_file_path)
        
        for page_num in range(len(doc)):
            page = doc.load_page(page_num)
            pix = page.get_pixmap()
            
            # Create output filename
            output_filename = pdf_file.replace(".pdf", "_page_" + str(page_num+1) + ".png")
            output_path = os.path.join(output_folder, output_filename)
            
            # Check if file already exists
            if os.path.exists(output_path):
                print(f"  -> {output_filename} already exists")
                
                # Ask user if they want to overwrite
                if not confirm_overwrite(output_filename):
                    continue
            
            # Save the image
            pix.save(output_path)
            print(f"  Saved: {output_filename}")
        
        doc.close()
    
    print("\nDone!")

if __name__ == "__main__":
    main()