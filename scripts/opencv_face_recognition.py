#!/usr/bin/env python3
"""
OpenCV Face Recognition Script for Drone Face Scanner API

This script handles:
- Face detection from images
- Face encoding/feature extraction
- Face matching and comparison
- Image processing and optimization

Requirements:
- Python 3.7+
- OpenCV (opencv-python)
- dlib
- face_recognition
"""

import sys
import json
import base64
import argparse
import numpy as np
import cv2

try:
    import face_recognition
    import dlib
    FACE_RECOGNITION_AVAILABLE = True
except ImportError:
    FACE_RECOGNITION_AVAILABLE = False
    print("Warning: face_recognition library not available, using basic OpenCV only", file=sys.stderr)

class FaceRecognitionProcessor:
    def __init__(self):
        """Initialize the face recognition processor"""
        self.cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
        self.available = FACE_RECOGNITION_AVAILABLE
        
    def detect_faces(self, image_path, confidence_threshold=0.75, max_faces=5):
        """
        Detect faces in an image
        
        Args:
            image_path: Path to the image file
            confidence_threshold: Minimum confidence for face detection
            max_faces: Maximum number of faces to return
            
        Returns:
            Dictionary with detection results
        """
        try:
            # Load image
            image = cv2.imread(image_path)
            if image is None:
                return {
                    'success': False,
                    'error': 'Failed to load image'
                }
            
            # Convert to grayscale for detection
            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
            
            faces = []
            
            if self.available:
                # Use face_recognition library for better accuracy
                face_locations = face_recognition.face_locations(image, model='hog')
                face_encodings = face_recognition.face_encodings(image, face_locations)
                
                for i, (location, encoding) in enumerate(zip(face_locations, face_encodings)):
                    if len(faces) >= max_faces:
                        break
                    
                    top, right, bottom, left = location
                    bounding_box = {
                        'top': int(top),
                        'right': int(right),
                        'bottom': int(bottom),
                        'left': int(left)
                    }
                    
                    # Convert encoding to bytes for storage
                    encoding_bytes = encoding.tobytes()
                    encoding_base64 = base64.b64encode(encoding_bytes).decode('utf-8')
                    
                    faces.append({
                        'bounding_box': bounding_box,
                        'encoding': encoding_base64,
                        'confidence': 0.95  # High confidence for face_recognition
                    })
            else:
                # Use OpenCV cascade classifier as fallback
                detected_faces = self.cascade.detectMultiScale(
                    gray,
                    scaleFactor=1.1,
                    minNeighbors=5,
                    minSize=(30, 30)
                )
                
                for i, (x, y, w, h) in enumerate(detected_faces):
                    if len(faces) >= max_faces:
                        break
                    
                    bounding_box = {
                        'left': int(x),
                        'top': int(y),
                        'right': int(x + w),
                        'bottom': int(y + h)
                    }
                    
                    # Generate simple encoding using face region features
                    face_region = image[y:y+h, x:x+w]
                    simple_encoding = self._generate_simple_encoding(face_region)
                    
                    faces.append({
                        'bounding_box': bounding_box,
                        'encoding': simple_encoding,
                        'confidence': 0.80  # Lower confidence for cascade classifier
                    })
            
            return {
                'success': True,
                'faces': faces,
                'faces_detected': len(faces),
                'method': 'face_recognition' if self.available else 'opencv_cascade'
            }
            
        except Exception as e:
            return {
                'success': False,
                'error': str(e)
            }
    
    def extract_face_encoding(self, image_path):
        """
        Extract face encoding from an image
        
        Args:
            image_path: Path to the image file
            
        Returns:
            Dictionary with encoding result
        """
        try:
            image = cv2.imread(image_path)
            if image is None:
                return {
                    'success': False,
                    'error': 'Failed to load image'
                }
            
            if self.available:
                # Use face_recognition library
                face_locations = face_recognition.face_locations(image, model='hog')
                
                if len(face_locations) == 0:
                    return {
                        'success': False,
                        'error': 'No face detected in image'
                    }
                
                # Use the first detected face
                face_encodings = face_recognition.face_encodings(image, face_locations)
                
                if len(face_encodings) == 0:
                    return {
                        'success': False,
                        'error': 'Failed to generate face encoding'
                    }
                
                encoding = face_encodings[0]
                encoding_bytes = encoding.tobytes()
                encoding_base64 = base64.b64encode(encoding_bytes).decode('utf-8')
                
                return {
                    'success': True,
                    'encoding': encoding_base64,
                    'confidence': 0.95,
                    'method': 'face_recognition'
                }
            else:
                # Fallback to simple encoding
                gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
                faces = self.cascade.detectMultiScale(gray, 1.1, 5)
                
                if len(faces) == 0:
                    return {
                        'success': False,
                        'error': 'No face detected in image'
                    }
                
                x, y, w, h = faces[0]
                face_region = gray[y:y+h, x:x+w]
                simple_encoding = self._generate_simple_encoding(face_region)
                
                return {
                    'success': True,
                    'encoding': simple_encoding,
                    'confidence': 0.75,
                    'method': 'opencv_cascade'
                }
                
        except Exception as e:
            return {
                'success': False,
                'error': str(e)
            }
    
    def compare_encodings(self, encoding1_path, encoding2_path):
        """
        Compare two face encodings
        
        Args:
            encoding1_path: Path to first encoding file
            encoding2_path: Path to second encoding file
            
        Returns:
            Dictionary with comparison result
        """
        try:
            # Load encodings
            with open(encoding1_path, 'rb') as f:
                encoding1_data = f.read()
            
            with open(encoding2_path, 'rb') as f:
                encoding2_data = f.read()
            
            # Try to decode as base64
            try:
                encoding1_bytes = base64.b64decode(encoding1_data)
                encoding2_bytes = base64.b64decode(encoding2_data)
            except:
                encoding1_bytes = encoding1_data
                encoding2_bytes = encoding2_data
            
            if self.available:
                # Use face_recognition for comparison
                encoding1_array = np.frombuffer(encoding1_bytes, dtype=np.float64)
                encoding2_array = np.frombuffer(encoding2_bytes, dtype=np.float64)
                
                # Reshape if needed
                if encoding1_array.ndim == 1:
                    encoding1_array = encoding1_array.reshape(1, -1)
                if encoding2_array.ndim == 1:
                    encoding2_array = encoding2_array.reshape(1, -1)
                
                # Calculate face distance
                distance = face_recognition.face_distance([encoding1_array], encoding2_array)[0]
                
                # Convert distance to confidence (lower distance = higher confidence)
                confidence = 1.0 - min(distance, 1.0)
                
                return {
                    'success': True,
                    'confidence': float(confidence),
                    'distance': float(distance),
                    'method': 'face_recognition'
                }
            else:
                # Simple byte comparison
                if len(encoding1_bytes) != len(encoding2_bytes):
                    return {
                        'success': True,
                        'confidence': 0.0,
                        'method': 'simple_comparison'
                    }
                
                # Calculate Hamming distance
                distance = sum(bin(a ^ b).count('1') for a, b in zip(encoding1_bytes, encoding2_bytes))
                max_distance = len(encoding1_bytes) * 8
                confidence = 1.0 - (distance / max_distance)
                
                return {
                    'success': True,
                    'confidence': float(confidence),
                    'method': 'simple_comparison'
                }
                
        except Exception as e:
            return {
                'success': False,
                'error': str(e)
            }
    
    def _generate_simple_encoding(self, face_region):
        """Generate simple encoding from face region (fallback method)"""
        try:
            # Resize to standard size
            resized = cv2.resize(face_region, (64, 64))
            
            # Calculate basic features
            mean_color = np.mean(resized)
            std_color = np.std(resized)
            
            # Flatten and encode
            features = resized.flatten()
            features = np.append(features, [mean_color, std_color])
            
            # Normalize
            features = (features - np.mean(features)) / (np.std(features) + 1e-8)
            
            # Convert to bytes
            features_bytes = features.tobytes()
            encoding_base64 = base64.b64encode(features_bytes).decode('utf-8')
            
            return encoding_base64
            
        except Exception as e:
            # Return empty encoding if everything fails
            return base64.b64encode(b'').decode('utf-8')

def main():
    """Main entry point for the script"""
    parser = argparse.ArgumentParser(description='OpenCV Face Recognition for Drone Scanner')
    parser.add_argument('image_path', help='Path to image file')
    parser.add_argument('confidence_threshold', type=float, help='Confidence threshold', default=0.75)
    parser.add_argument('max_faces', type=int, help='Maximum faces to detect', default=5)
    parser.add_argument('operation', help='Operation: detect, encode, or compare', 
                       choices=['detect', 'encode', 'compare'], default='detect')
    parser.add_argument('encoding2_path', nargs='?', help='Second encoding path for comparison')
    
    args = parser.parse_args()
    
    # Create processor
    processor = FaceRecognitionProcessor()
    
    result = None
    
    if args.operation == 'detect':
        result = processor.detect_faces(args.image_path, args.confidence_threshold, args.max_faces)
    elif args.operation == 'encode':
        result = processor.extract_face_encoding(args.image_path)
    elif args.operation == 'compare':
        if not args.encoding2_path:
            result = {'success': False, 'error': 'Second encoding path required for comparison'}
        else:
            result = processor.compare_encodings(args.image_path, args.encoding2_path)
    
    # Output result as JSON
    print(json.dumps(result, indent=2))

if __name__ == '__main__':
    main()